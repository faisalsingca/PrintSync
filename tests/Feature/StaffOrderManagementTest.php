<?php

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\OrderStatus;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function createStaffOrderManagementOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => Carbon::parse('2026-10-08 10:15:00'),
        'order_type' => 'business cards',
        'description' => 'Matte finish',
        'quantity' => 250,
        'status' => 'pending',
    ], $attributes));
}

function assignStaffOrderManagementOrder(Order $order, User $staff): void
{
    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
    ]);
}

test('staff dashboard counts only orders assigned to the authenticated staff member by current status', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $anotherStaff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $pendingOrder = createStaffOrderManagementOrder($customer, ['status' => 'pending']);
    $processingOrder = createStaffOrderManagementOrder($customer, ['status' => 'processing']);
    $otherStaffOrder = createStaffOrderManagementOrder($customer, ['status' => 'completed']);
    assignStaffOrderManagementOrder($pendingOrder, $staff);
    assignStaffOrderManagementOrder($processingOrder, $staff);
    assignStaffOrderManagementOrder($otherStaffOrder, $anotherStaff);

    $this->actingAs($staff)
        ->get(route('staff.dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Assigned Orders', '2', 'Pending', '1', 'In Progress', '1', 'Ready for Pickup', '0', 'Completed', '0']);
});

test('staff orders page shows only assigned orders with customer and order summary', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $anotherStaff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Assigned Customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer', 'name' => 'Other Customer']);
    $assignedOrder = createStaffOrderManagementOrder($customer, [
        'order_type' => 'custom flyers',
        'quantity' => 500,
        'status' => 'processing',
    ]);
    $otherStaffOrder = createStaffOrderManagementOrder($anotherCustomer, [
        'order_type' => 'private order type',
    ]);
    assignStaffOrderManagementOrder($assignedOrder, $staff);
    assignStaffOrderManagementOrder($otherStaffOrder, $anotherStaff);

    $this->actingAs($staff)
        ->get(route('staff.orders'))
        ->assertOk()
        ->assertSee('#'.$assignedOrder->order_id)
        ->assertSee('custom flyers')
        ->assertSee('500 copies')
        ->assertSee('Oct 8, 2026')
        ->assertSee('Assigned Customer')
        ->assertSee('In Progress')
        ->assertSee(route('staff.orders.show', $assignedOrder))
        ->assertDontSee('private order type')
        ->assertDontSee('Other Customer');
});

test('staff can open details for an assigned order', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Order Details Customer']);
    $order = createStaffOrderManagementOrder($customer, [
        'order_type' => 'event posters',
        'status' => 'processing',
    ]);
    assignStaffOrderManagementOrder($order, $staff);

    $this->actingAs($staff)
        ->get(route('staff.orders.show', $order))
        ->assertOk()
        ->assertSee('Order Details')
        ->assertSee('Order Details Customer')
        ->assertSee('event posters')
        ->assertSee('250 copies')
        ->assertSee('Matte finish')
        ->assertSee('In Progress');
});

test('staff receive forbidden when opening an order assigned to another staff member', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $assignedStaff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    assignStaffOrderManagementOrder($order, $assignedStaff);

    $this->actingAs($staff)
        ->get(route('staff.orders.show', $order))
        ->assertForbidden();
});

test('non-staff users cannot access staff order details', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    assignStaffOrderManagementOrder($order, $staff);

    $this->actingAs($user)
        ->get(route('staff.orders.show', $order))
        ->assertForbidden();
})->with([
    'customer' => ['customer'],
    'cashier' => ['cashier'],
    'owner' => ['owner'],
]);

test('assigned staff can advance an order through each production status', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    assignStaffOrderManagementOrder($order, $staff);
    $this->actingAs($staff);

    Livewire::test('pages::staff.order-details', ['order' => $order])
        ->call('updateProductionStatus', OrderStatus::InProgress->value)
        ->assertHasNoErrors()
        ->assertSee('In Progress')
        ->call('updateProductionStatus', OrderStatus::ReadyForPickup->value)
        ->assertHasNoErrors()
        ->assertSee('Ready for Pickup')
        ->call('updateProductionStatus', OrderStatus::Completed->value)
        ->assertHasNoErrors()
        ->assertSee('Completed');

    $this->assertDatabaseHas('orders', [
        'order_id' => $order->order_id,
        'status' => OrderStatus::Completed->value,
    ]);
    $this->assertDatabaseHas('order_assignments', [
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
        'assignment_status' => OrderAssignment::STATUS_ACCEPTED,
        'is_active' => true,
    ]);

    $this->actingAs($customer)
        ->get(route('customer.orders'))
        ->assertOk()
        ->assertSee('Completed');

    $this->get(route('customer.orders.show', $order))
        ->assertOk()
        ->assertSee('Completed');

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.monitoring'))
        ->assertOk()
        ->assertSee('Completed');
});

test('assigned staff cannot skip or reverse a production status', function (string $currentStatus, string $requestedStatus) {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer, ['status' => $currentStatus]);
    assignStaffOrderManagementOrder($order, $staff);
    $this->actingAs($staff);

    Livewire::test('pages::staff.order-details', ['order' => $order])
        ->call('updateProductionStatus', $requestedStatus)
        ->assertHasErrors(['status']);

    $this->assertDatabaseHas('orders', [
        'order_id' => $order->order_id,
        'status' => $currentStatus,
    ]);
})->with([
    'pending cannot skip to ready' => ['pending', 'ready_for_pickup'],
    'in progress cannot return to pending' => ['processing', 'pending'],
    'completed cannot advance again' => ['completed', 'completed'],
]);

test('staff cannot update an order assigned to someone else', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $assignedStaff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    assignStaffOrderManagementOrder($order, $assignedStaff);

    $this->actingAs($staff)
        ->get(route('staff.orders.show', $order))
        ->assertForbidden();

    $this->assertDatabaseHas('orders', [
        'order_id' => $order->order_id,
        'status' => 'pending',
    ]);
});

test('offered staff tasks cannot update production status before acceptance', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
        'assignment_status' => OrderAssignment::STATUS_OFFERED,
        'is_active' => true,
    ]);
    $this->actingAs($staff);

    Livewire::test('pages::staff.order-details', ['order' => $order])
        ->call('updateProductionStatus', OrderStatus::InProgress->value)
        ->assertForbidden();

    $this->assertDatabaseHas('orders', [
        'order_id' => $order->order_id,
        'status' => 'pending',
    ]);
});
