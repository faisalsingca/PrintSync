<?php

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\OrderServiceLine;
use App\OrderStatus;
use App\Services\PaymentService;
use App\StaffSpecialtyType;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function createStaffOrderManagementOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => Carbon::parse('2026-10-08 10:15:00'),
        'order_type' => 'business cards',
        'service_line' => OrderServiceLine::GraphicDesign->value,
        'description' => 'Matte finish',
        'quantity' => 250,
        'status' => 'pending',
    ], $attributes));
}

function assignStaffOrderManagementOrder(Order $order, User $staff): void
{
    StaffSpecialty::query()->firstOrCreate([
        'staff_id' => $staff->id,
        'specialty' => StaffSpecialtyType::GraphicDesigner->value,
    ]);

    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
    ]);
}

function prepareStaffOrderManagementOrderForProduction(Order $order): void
{
    $order->forceFill(['confirmed_total' => '250.00'])->save();

    (new PaymentService)->recordPayment(
        $order,
        User::factory()->create(['role' => 'cashier']),
        'cash',
        '1.00',
        Carbon::parse('2026-10-08 10:30:00'),
    );
}

test('staff dashboard counts orders matching the authenticated staff member specialty by current status', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    StaffSpecialty::query()->create([
        'staff_id' => $staff->id,
        'specialty' => StaffSpecialtyType::GraphicDesigner->value,
    ]);
    $pendingOrder = createStaffOrderManagementOrder($customer, ['status' => 'pending']);
    $processingOrder = createStaffOrderManagementOrder($customer, ['status' => 'processing']);
    createStaffOrderManagementOrder($customer, [
        'service_line' => OrderServiceLine::BookBinding->value,
        'status' => 'completed',
    ]);
    $this->actingAs($staff)
        ->get(route('staff.dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Assigned Orders', '2', 'Pending', '1', 'In Progress', '1', 'Ready for Pickup', '0', 'Completed', '0']);
});

test('staff orders page shows only orders matching the staff specialty with customer and order summary', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Assigned Customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer', 'name' => 'Other Customer']);
    StaffSpecialty::query()->create([
        'staff_id' => $staff->id,
        'specialty' => StaffSpecialtyType::GraphicDesigner->value,
    ]);
    $assignedOrder = createStaffOrderManagementOrder($customer, [
        'order_type' => 'custom flyers',
        'quantity' => 500,
        'status' => 'processing',
    ]);
    createStaffOrderManagementOrder($anotherCustomer, [
        'order_type' => 'private order type',
        'service_line' => OrderServiceLine::BookBinding->value,
    ]);
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

test('eligible staff cannot update an order until it has a primary assignment', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    StaffSpecialty::query()->create([
        'staff_id' => $staff->id,
        'specialty' => StaffSpecialtyType::GraphicDesigner->value,
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
    prepareStaffOrderManagementOrderForProduction($order);
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
        'is_active' => null,
    ]);

    $this->actingAs($customer)
        ->get(route('customer.orders'))
        ->assertOk()
        ->assertSee('Completed');

    $this->get(route('customer.orders.show', $order))
        ->assertOk()
        ->assertSee('Completed');

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.orders-status'))
        ->assertOk()
        ->assertSee('Completed');
});

test('eligible staff can take over an order with a reason while preserving its original assignee', function () {
    $originalStaff = User::factory()->create(['role' => 'staff']);
    $coveringStaff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer);
    prepareStaffOrderManagementOrderForProduction($order);
    assignStaffOrderManagementOrder($order, $originalStaff);
    StaffSpecialty::query()->create([
        'staff_id' => $coveringStaff->id,
        'specialty' => StaffSpecialtyType::GraphicDesigner->value,
    ]);

    $this->actingAs($coveringStaff);

    Livewire::test('pages::staff.order-details', ['order' => $order])
        ->call('takeOver')
        ->assertHasErrors(['takeoverReason' => 'required'])
        ->set('takeoverReason', 'Original assignee is unavailable')
        ->call('takeOver')
        ->assertHasNoErrors()
        ->assertSee($originalStaff->name)
        ->assertSee($coveringStaff->name)
        ->assertSee('Original assignee is unavailable')
        ->call('updateProductionStatus', OrderStatus::InProgress->value)
        ->assertHasNoErrors()
        ->assertSee('In Progress');

    $this->assertDatabaseHas('order_assignments', [
        'order_id' => $order->order_id,
        'staff_id' => $originalStaff->id,
        'assignment_status' => OrderAssignment::STATUS_ACCEPTED,
        'is_active' => true,
    ]);
    $this->assertDatabaseHas('order_takeovers', [
        'order_id' => $order->order_id,
        'assignment_id' => $order->assignments()->sole()->assignment_id,
        'covering_staff_id' => $coveringStaff->id,
        'reason' => 'Original assignee is unavailable',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'staff.order_taken_over',
        'user_id' => $coveringStaff->id,
        'subject_id' => $order->takeovers()->sole()->takeover_id,
    ]);

    $owner = User::factory()->create(['role' => 'owner']);
    $cashier = User::factory()->create(['role' => 'cashier', 'email_verified_at' => now()]);

    $this->actingAs($owner)
        ->get(route('owner.routing'))
        ->assertOk()
        ->assertSee($originalStaff->name)
        ->assertSee($coveringStaff->name)
        ->assertSee('Original assignee is unavailable');

    $this->actingAs($cashier)
        ->get(route('cashier.routing'))
        ->assertOk()
        ->assertSee($originalStaff->name)
        ->assertSee($coveringStaff->name)
        ->assertSee('Original assignee is unavailable')
        ->assertSee(route('cashier.orders.show', $order), false);

    $this->actingAs($originalStaff);

    Livewire::test('pages::staff.order-details', ['order' => $order])
        ->call('updateProductionStatus', OrderStatus::ReadyForPickup->value)
        ->assertForbidden();
});

test('assigned staff cannot skip or reverse a production status', function (string $currentStatus, string $requestedStatus) {
    $staff = User::factory()->create(['role' => 'staff']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createStaffOrderManagementOrder($customer, ['status' => $currentStatus]);
    prepareStaffOrderManagementOrderForProduction($order);
    assignStaffOrderManagementOrder($order, $staff);
    $this->actingAs($staff);

    $component = Livewire::test('pages::staff.order-details', ['order' => $order])
        ->call('updateProductionStatus', $requestedStatus);

    if ($currentStatus === OrderStatus::Completed->value) {
        $component->assertForbidden();
    } else {
        $component->assertHasErrors(['status']);
    }

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
    StaffSpecialty::query()->create([
        'staff_id' => $staff->id,
        'specialty' => StaffSpecialtyType::GraphicDesigner->value,
    ]);
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
