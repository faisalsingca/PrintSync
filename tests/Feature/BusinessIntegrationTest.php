<?php

use App\Http\Requests\StoreInventoryAlertRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreStockInRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

function createBusinessIntegrationOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
        'quantity' => 1,
    ], $attributes));
}

function confirmBusinessIntegrationOrderTotal(Order $order, string $total): Order
{
    $order->confirmed_total = $total;
    $order->total_confirmed_at = now();
    $order->save();

    return $order;
}

function createBusinessIntegrationInventory(string $name, string $quantity = '10.000'): Inventory
{
    return Inventory::create([
        'material_name' => $name,
        'quantity' => $quantity,
        'unit' => 'ream',
        'status' => 'available',
    ]);
}

test('a customer can create and view their own order but not another customer order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    $orderAttributes = [
        'order_type' => 'business cards',
        'service_line' => 'graphic_design',
        'description' => 'Matte finish',
        'quantity' => 250,
    ];
    $forgedCustomerAttributes = array_merge($orderAttributes, ['customer_id' => $anotherCustomer->id]);

    expect(Validator::make($forgedCustomerAttributes, (new StoreOrderRequest)->rules())->fails())
        ->toBeTrue();

    $validatedOrderAttributes = Validator::make($orderAttributes, (new StoreOrderRequest)->rules())
        ->validated();
    $order = (new OrderService)->createOrder(array_merge($validatedOrderAttributes, [
        'customer_id' => $customer->id,
        'order_date' => now(),
    ]));

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer->is($customer))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($anotherCustomer)->allows('view', $order))->toBeFalse();
});

test('order assignments connect the staff member and authorize only assigned staff', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $anotherStaff = User::factory()->create(['role' => 'staff']);
    $order = createBusinessIntegrationOrder($customer);
    StaffSpecialty::create([
        'staff_id' => $staff->id,
        'specialty' => 'graphic_designer',
    ]);

    $assignment = (new OrderService)->assignOrderToStaff($order, $staff);

    expect($order->assignments()->first()->is($assignment))->toBeTrue()
        ->and($assignment->staff->is($staff))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($anotherStaff)->allows('view', $order))->toBeFalse();
});

test('validated payment details are recorded and available through the order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $anotherRole = User::factory()->create(['role' => 'owner']);
    $order = createBusinessIntegrationOrder($customer);
    confirmBusinessIntegrationOrderTotal($order, '500.00');
    $paymentAttributes = [
        'order_id' => $order->order_id,
        'payment_method' => 'gcash',
        'received' => '350.00',
        'payment_date' => '2026-10-08 16:30:00',
    ];
    $validatedPaymentAttributes = Validator::make(
        $paymentAttributes,
        (new StorePaymentRequest)->rules(),
    )->validated();

    $payment = (new PaymentService)->recordPayment(
        $order,
        $cashier,
        $validatedPaymentAttributes['payment_method'],
        $validatedPaymentAttributes['received'],
        Carbon::parse($validatedPaymentAttributes['payment_date']),
    );

    expect($payment->order->is($order))->toBeTrue()
        ->and($payment->cashier->is($cashier))->toBeTrue()
        ->and($order->payments()->first()->is($payment))->toBeTrue()
        ->and(Gate::forUser($cashier)->allows('create', Payment::class))->toBeTrue()
        ->and(Gate::forUser($anotherRole)->allows('create', Payment::class))->toBeFalse();
});

test('receipt information includes the order customer and only its payments', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createBusinessIntegrationOrder($customer);
    $anotherOrder = createBusinessIntegrationOrder($customer);
    confirmBusinessIntegrationOrderTotal($order, '500.00');
    confirmBusinessIntegrationOrderTotal($anotherOrder, '500.00');
    $payment = (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'cash',
        '75.00',
        Carbon::parse('2026-10-08 17:00:00'),
    );
    (new PaymentService)->recordPayment(
        $anotherOrder,
        $cashier,
        'cash',
        '25.00',
        Carbon::parse('2026-10-08 17:05:00'),
    );
    $receiptService = new ReceiptService;

    $receiptOrder = $receiptService->getOrderInformationForReceipt($order);
    $receiptPayments = $receiptService->getPaymentInformationForReceipt($order);

    expect($receiptOrder->customer->is($customer))->toBeTrue()
        ->and($receiptPayments->modelKeys())->toBe([$payment->payment_id]);
});

test('stock-in validation, inventory updates, relationships, and cashier authorization work together', function () {
    $staff = User::factory()->create(['role' => 'cashier']);
    $inventory = createBusinessIntegrationInventory('Copy paper');
    $stockInAttributes = [
        'inventory_id' => $inventory->inventory_id,
        'quantity_added' => '2.500',
    ];
    $validatedStockInAttributes = Validator::make(
        $stockInAttributes,
        (new StoreStockInRequest)->rules(),
    )->validated();

    $stockIn = (new InventoryService)->addStock(
        $inventory,
        $staff,
        $validatedStockInAttributes['quantity_added'],
    );

    expect($inventory->fresh()->quantity)->toBe('12.500')
        ->and($stockIn->inventory->is($inventory))->toBeTrue()
        ->and($stockIn->staff->is($staff))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('addStock', Inventory::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create(['role' => 'staff']))->allows('addStock', Inventory::class))->toBeFalse();
});

test('low-stock alert validation creates a related alert authorized for staff only', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $owner = User::factory()->create(['role' => 'owner']);
    $inventory = createBusinessIntegrationInventory('Cardstock');
    $alertAttributes = [
        'inventory_id' => $inventory->inventory_id,
        'remarks' => 'Reorder soon.',
    ];
    $validatedAlertAttributes = Validator::make(
        $alertAttributes,
        (new StoreInventoryAlertRequest)->rules(),
    )->validated();

    $alert = (new InventoryService)->createLowStockAlert(
        $inventory,
        $staff,
        $validatedAlertAttributes['remarks'],
    );

    expect($alert->alert_type)->toBe('low_stock')
        ->and($alert->inventory->is($inventory))->toBeTrue()
        ->and($alert->staff->is($staff))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('createLowStockAlert', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('createLowStockAlert', Inventory::class))->toBeFalse();
});

test('role access remains correctly scoped across orders inventory and payments', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $owner = User::factory()->create(['role' => 'owner']);
    $order = createBusinessIntegrationOrder($customer);
    confirmBusinessIntegrationOrderTotal($order, '500.00');
    $inventory = createBusinessIntegrationInventory('Vinyl');
    (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'bank_transfer',
        '500.00',
        Carbon::parse('2026-10-08 18:00:00'),
    );

    $this->assertModelExists($inventory);

    expect(Gate::forUser($cashier)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('viewAny', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('viewAny', Inventory::class))->toBeFalse()
        ->and(Gate::forUser($cashier)->allows('viewAny', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($cashier)->allows('manage', Inventory::class))->toBeTrue()
        ->and(Gate::forUser($cashier)->allows('viewAny', Payment::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', Payment::class))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('create', Payment::class))->toBeFalse();
});