<?php

use App\Http\Requests\StoreInventoryAlertRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreStockInRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\Payment;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('public registration ignores client roles and always creates a customer', function (?string $submittedRole) {
    $registration = [
        'name' => 'New PrintSync User',
        'email' => 'new-user@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    if ($submittedRole !== null) {
        $registration['role'] = $submittedRole;
    }

    $this->post(route('register.store'), $registration)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'new-user@example.com',
        'role' => 'customer',
    ]);
})->with([
    'role omitted' => [null],
    'owner role submitted' => ['owner'],
    'staff role submitted' => ['staff'],
    'cashier role submitted' => ['cashier'],
]);

test('request validation rejects client-controlled ownership and alert fields', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $staff = User::factory()->create(['role' => 'staff']);
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
    ]);
    $inventory = Inventory::create([
        'material_name' => 'Security test paper',
        'quantity' => '10.000',
        'unit' => 'ream',
        'status' => 'available',
    ]);

    $orderValidator = Validator::make([
        'customer_id' => $customer->id,
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
        'quantity' => 1,
    ], (new StoreOrderRequest)->rules());
    $paymentValidator = Validator::make([
        'order_id' => $order->order_id,
        'cashier_id' => $cashier->id,
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ], (new StorePaymentRequest)->rules());
    $stockInValidator = Validator::make([
        'inventory_id' => $inventory->inventory_id,
        'staff_id' => $staff->id,
        'quantity_added' => '1.000',
    ], (new StoreStockInRequest)->rules());
    $alertValidator = Validator::make([
        'inventory_id' => $inventory->inventory_id,
        'staff_id' => $staff->id,
        'alert_type' => 'out_of_stock',
    ], (new StoreInventoryAlertRequest)->rules());

    expect($orderValidator->errors()->has('customer_id'))->toBeTrue()
        ->and($paymentValidator->errors()->has('cashier_id'))->toBeTrue()
        ->and($stockInValidator->errors()->has('staff_id'))->toBeTrue()
        ->and($alertValidator->errors()->has('staff_id'))->toBeTrue()
        ->and($alertValidator->errors()->has('alert_type'))->toBeTrue();
});

test('order creation uses the authenticated customer rather than a submitted customer_id', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $attackedCustomer = User::factory()->create(['role' => 'customer']);
    $submittedAttributes = [
        'customer_id' => $attackedCustomer->id,
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
        'quantity' => 10,
    ];
    $attackValidator = Validator::make($submittedAttributes, (new StoreOrderRequest)->rules());

    expect($attackValidator->errors()->has('customer_id'))->toBeTrue();

    $this->actingAs($customer);
    $validatedAttributes = Validator::make([
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
        'quantity' => 10,
    ], (new StoreOrderRequest)->rules())->validated();
    $order = (new OrderService)->createOrder(array_merge($validatedAttributes, [
        'customer_id' => auth()->id(),
        'order_date' => now(),
    ]));

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer->is($customer))->toBeTrue();
});

test('payment recording uses the authenticated cashier instead of a submitted cashier_id', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $attackedCashier = User::factory()->create(['role' => 'cashier']);
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
    ]);
    $order->confirmed_total = '500.00';
    $order->total_confirmed_at = now();
    $order->save();
    $attackValidator = Validator::make([
        'order_id' => $order->order_id,
        'cashier_id' => $attackedCashier->id,
        'payment_method' => 'cash',
        'received' => '100.00',
        'payment_date' => '2026-10-08 12:00:00',
    ], (new StorePaymentRequest)->rules());

    expect($attackValidator->errors()->has('cashier_id'))->toBeTrue();

    $this->actingAs($cashier);
    $payment = (new PaymentService)->recordPayment(
        $order,
        auth()->user(),
        'cash',
        '100.00',
        Carbon::parse('2026-10-08 12:00:00'),
    );

    expect($payment->cashier_id)->toBe($cashier->id)
        ->and($payment->cashier->is($cashier))->toBeTrue()
        ->and($order->payments()->first()->is($payment))->toBeTrue();
});

test('stock-in and low-stock alerts use the authenticated staff member and fixed alert type', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $attackedStaff = User::factory()->create(['role' => 'staff']);
    $inventory = Inventory::create([
        'material_name' => 'Security test cardstock',
        'quantity' => '5.000',
        'unit' => 'ream',
        'status' => 'available',
    ]);
    $stockInAttackValidator = Validator::make([
        'inventory_id' => $inventory->inventory_id,
        'staff_id' => $attackedStaff->id,
        'quantity_added' => '2.000',
    ], (new StoreStockInRequest)->rules());
    $alertAttackValidator = Validator::make([
        'inventory_id' => $inventory->inventory_id,
        'staff_id' => $attackedStaff->id,
        'alert_type' => 'out_of_stock',
    ], (new StoreInventoryAlertRequest)->rules());

    expect($stockInAttackValidator->errors()->has('staff_id'))->toBeTrue()
        ->and($alertAttackValidator->errors()->has('staff_id'))->toBeTrue()
        ->and($alertAttackValidator->errors()->has('alert_type'))->toBeTrue();

    $this->actingAs($staff);
    $inventoryService = new InventoryService;
    $stockIn = $inventoryService->addStock($inventory, auth()->user(), '2.000');
    $alert = $inventoryService->createLowStockAlert(
        $inventory,
        auth()->user(),
        'Reorder soon.',
    );

    expect($stockIn->staff_id)->toBe($staff->id)
        ->and($stockIn->staff->is($staff))->toBeTrue()
        ->and($alert->staff_id)->toBe($staff->id)
        ->and($alert->staff->is($staff))->toBeTrue()
        ->and($alert->alert_type)->toBe('low_stock')
        ->and($alert->inventory->is($inventory))->toBeTrue();
});

test('policies preserve role boundaries for real order inventory and payment records', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $unassignedStaff = User::factory()->create(['role' => 'staff']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $owner = User::factory()->create(['role' => 'owner']);
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
    ]);
    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
    ]);
    $inventory = Inventory::create([
        'material_name' => 'Security policy paper',
        'quantity' => '10.000',
        'unit' => 'ream',
        'status' => 'available',
    ]);

    expect(Gate::forUser($customer)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($anotherCustomer)->allows('view', $order))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($unassignedStaff)->allows('view', $order))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('viewAny', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('viewAny', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('viewAny', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('addStock', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('addStock', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('addStock', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('addStock', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('createLowStockAlert', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('createLowStockAlert', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('createLowStockAlert', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('createLowStockAlert', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('viewAny', Payment::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', Payment::class))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('viewAny', Payment::class))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('viewAny', Payment::class))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('create', Payment::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', Payment::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('create', Payment::class))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('create', Payment::class))->toBeFalse();
});
