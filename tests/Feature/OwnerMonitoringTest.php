<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function createOwnerMonitoringOrder(User $customer, string $status, string $orderType): Order
{
    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => $orderType,
        'quantity' => 25,
        'status' => $status,
    ]);
}

function createOwnerMonitoringInventory(string $materialName, string $quantity): Inventory
{
    return Inventory::create([
        'material_name' => $materialName,
        'quantity' => $quantity,
        'unit' => 'ream',
        'status' => 'available',
    ]);
}

function confirmOwnerMonitoringOrderTotal(Order $order, string $total): Order
{
    $order->confirmed_total = $total;
    $order->total_confirmed_at = now();
    $order->save();

    return $order;
}

test('owner dashboard shows current status groups, saved totals, inventory summaries, and recent payments', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $pendingOrder = createOwnerMonitoringOrder($customer, 'pending', 'posters');
    $processingOrder = createOwnerMonitoringOrder($customer, 'processing', 'business cards');
    confirmOwnerMonitoringOrderTotal($pendingOrder, '1000.00');
    confirmOwnerMonitoringOrderTotal($processingOrder, '1000.00');
    $lowStockInventory = createOwnerMonitoringInventory('Cardstock', '0.250');
    createOwnerMonitoringInventory('Small quantity, no alert', '0.001');
    (new InventoryService)->createLowStockAlert(
        $lowStockInventory,
        User::factory()->create(['role' => 'staff']),
        'Reorder soon.',
        Carbon::parse('2026-10-08 15:00:00'),
    );
    $paymentService = new PaymentService;
    $paymentService->recordPayment(
        $pendingOrder,
        $cashier,
        'bank_transfer',
        '150.25',
        Carbon::parse('2026-10-08 16:30:00'),
    );
    $paymentService->recordPayment(
        $processingOrder,
        $cashier,
        'cash',
        '12.34',
        Carbon::parse('2026-10-09 09:00:00'),
    );

    $this->actingAs($owner)
        ->get(route('owner.dashboard'))
        ->assertOk()
        ->assertSee('Orders by Current Status')
        ->assertSee('Pending')
        ->assertSee('Processing')
        ->assertSee('Inventory Materials')
        ->assertSee('Recorded Low-Stock Alerts')
        ->assertSee('Saved Payments')
        ->assertSee('2')
        ->assertSee('1')
        ->assertSee('162.59')
        ->assertSee('Order #'.$processingOrder->order_id)
        ->assertSee('Cash')
        ->assertSee('12.34')
        ->assertSee(route('owner.monitoring'), false);

    Livewire::test('pages::owner.dashboard')
        ->assertSet('orderStatusCounts', [
            ['status' => 'pending', 'count' => 1],
            ['status' => 'processing', 'count' => 1],
        ])
        ->assertSet('orderCount', 2);
});

test('owner monitoring displays current inventory, recorded alerts, order groups, and saved payment records', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOwnerMonitoringOrder($customer, 'processing', 'flyers');
    confirmOwnerMonitoringOrderTotal($order, '1000.00');
    $alertedInventory = createOwnerMonitoringInventory('Alerted cardstock', '0.250');
    createOwnerMonitoringInventory('Unalerted paper', '0.001');
    (new InventoryService)->createLowStockAlert(
        $alertedInventory,
        User::factory()->create(['role' => 'staff']),
        'Staff noted low stock.',
        Carbon::parse('2026-10-08 15:00:00'),
    );
    (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'gcash',
        '75.50',
        Carbon::parse('2026-10-08 16:30:00'),
    );

    $this->actingAs($owner)
        ->get(route('owner.monitoring'))
        ->assertOk()
        ->assertSee('Orders by Current Status')
        ->assertSee('In Progress')
        ->assertSee('Inventory Materials')
        ->assertSee('Alerted cardstock')
        ->assertSee('0.250 ream')
        ->assertSee('Unalerted paper')
        ->assertSee('0.001 ream')
        ->assertSee('Recorded Low-Stock Alerts')
        ->assertSee('Staff noted low stock.')
        ->assertSee('Oct 8, 2026 3:00 PM')
        ->assertSee('Saved Payments')
        ->assertSee('75.50')
        ->assertSee('GCash')
        ->assertSee('Order #'.$order->order_id)
        ->assertSee(route('owner.dashboard'), false);

    $this->assertDatabaseCount('inventory_alerts', 1);
    $this->assertDatabaseCount('payments', 1);
});

test('non-owners cannot access owner dashboard or monitoring', function (string $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'customer dashboard' => ['customer', 'owner.dashboard'],
    'staff dashboard' => ['staff', 'owner.dashboard'],
    'cashier dashboard' => ['cashier', 'owner.dashboard'],
    'customer monitoring' => ['customer', 'owner.monitoring'],
    'staff monitoring' => ['staff', 'owner.monitoring'],
    'cashier monitoring' => ['cashier', 'owner.monitoring'],
]);
