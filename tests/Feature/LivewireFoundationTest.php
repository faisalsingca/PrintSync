<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;

$livewirePagesByRole = [
    'customer' => ['customer.dashboard', 'customer.orders', 'customer.orders.create'],
    'staff' => ['staff.dashboard', 'staff.orders', 'staff.inventory'],
    'cashier' => ['cashier.dashboard', 'cashier.payments'],
    'owner' => ['owner.dashboard', 'owner.monitoring'],
];

$allLivewireRoles = array_keys($livewirePagesByRole);
$unauthorizedLivewireAccessCases = [];

foreach ($livewirePagesByRole as $allowedRole => $routeNames) {
    foreach ($routeNames as $routeName) {
        foreach (array_diff($allLivewireRoles, [$allowedRole]) as $role) {
            $unauthorizedLivewireAccessCases["{$role} cannot access {$routeName}"] = [$role, $routeName];
        }
    }
}

function createLivewireFoundationOrder(User $customer, string $orderType): Order
{
    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => $orderType,
        'quantity' => 1,
    ]);
}

test('each role can access its own Livewire pages', function (string $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertOk();
})->with([
    'customer dashboard' => ['customer', 'customer.dashboard'],
    'customer orders' => ['customer', 'customer.orders'],
    'customer create order' => ['customer', 'customer.orders.create'],
    'staff dashboard' => ['staff', 'staff.dashboard'],
    'staff orders' => ['staff', 'staff.orders'],
    'staff inventory' => ['staff', 'staff.inventory'],
    'cashier dashboard' => ['cashier', 'cashier.dashboard'],
    'cashier payments' => ['cashier', 'cashier.payments'],
    'owner dashboard' => ['owner', 'owner.dashboard'],
    'owner monitoring' => ['owner', 'owner.monitoring'],
]);

test('incorrect roles receive forbidden for role-protected pages', function (string $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertForbidden();
})->with($unauthorizedLivewireAccessCases);

test('guests are redirected to login from role-protected pages', function (string $routeName) {
    $this->get(route($routeName))
        ->assertRedirect(route('login'));
})->with(array_merge(...array_values($livewirePagesByRole)));

test('customer orders page shows only the authenticated customer orders', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    createLivewireFoundationOrder($customer, 'customer-owned-order');
    createLivewireFoundationOrder($anotherCustomer, 'other-customer-order');

    $this->actingAs($customer)
        ->get(route('customer.orders'))
        ->assertOk()
        ->assertSee('customer-owned-order')
        ->assertDontSee('other-customer-order');
});

test('staff inventory page retrieves inventory through InventoryService', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    Inventory::create([
        'material_name' => 'Staff visible paper',
        'quantity' => '12.000',
        'unit' => 'ream',
        'status' => 'available',
    ]);

    $this->actingAs($staff)
        ->get(route('staff.inventory'))
        ->assertOk()
        ->assertSee('Staff visible paper');
});

test('cashier payments page shows only payments recorded by the authenticated cashier', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $anotherCashier = User::factory()->create(['role' => 'cashier']);
    $order = createLivewireFoundationOrder($customer, 'payment-test-order');
    $anotherOrder = createLivewireFoundationOrder($customer, 'another-payment-test-order');
    $order->confirmed_total = '1000.00';
    $order->total_confirmed_at = now();
    $order->save();
    $anotherOrder->confirmed_total = '1000.00';
    $anotherOrder->total_confirmed_at = now();
    $anotherOrder->save();
    $paymentService = new PaymentService;

    $paymentService->recordPayment(
        $order,
        $cashier,
        'cash',
        '183.74',
        Carbon::parse('2026-10-08 19:00:00'),
    );
    $paymentService->recordPayment(
        $anotherOrder,
        $anotherCashier,
        'cash',
        '927.36',
        Carbon::parse('2026-10-08 19:05:00'),
    );

    $this->actingAs($cashier)
        ->get(route('cashier.payments'))
        ->assertOk()
        ->assertSee('183.74')
        ->assertDontSee('927.36');
});

test('owner monitoring displays saved order inventory and payment information', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createLivewireFoundationOrder($customer, 'monitoring-test-order');
    Inventory::create([
        'material_name' => 'Monitoring inventory',
        'quantity' => '4.000',
        'unit' => 'ream',
        'status' => 'available',
    ]);
    Payment::create([
        'order_id' => $order->order_id,
        'cashier_id' => $cashier->id,
        'payment_method' => 'cash',
        'received' => '50.00',
        'payment_date' => Carbon::parse('2026-10-08 19:10:00'),
    ]);

    $this->actingAs($owner)
        ->get(route('owner.monitoring'))
        ->assertOk()
        ->assertSee('Pending')
        ->assertSee('Monitoring inventory')
        ->assertSee('4.000 ream')
        ->assertSee('Cash')
        ->assertSee('50.00');
});
