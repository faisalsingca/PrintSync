<?php

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\OrderServiceLine;
use App\Services\OrderService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function createOrderRoutingTestOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'Custom poster',
        'service_line' => OrderServiceLine::GraphicDesign->value,
        'quantity' => 25,
        'status' => 'pending',
    ], $attributes));
}

function addOrderRoutingTestSpecialty(User $staff, string $specialty): StaffSpecialty
{
    return StaffSpecialty::create([
        'staff_id' => $staff->id,
        'specialty' => $specialty,
    ]);
}

test('routing rejects staff without a matching service specialty', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $bookBinder = User::factory()->create(['role' => 'staff']);
    $order = createOrderRoutingTestOrder($customer);

    addOrderRoutingTestSpecialty($bookBinder, 'book_binder');

    expect(fn () => (new OrderService)->routeOrder(
        $order,
        $bookBinder,
        OrderServiceLine::GraphicDesign,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('order_assignments', 0);
});

test('machine operators are eligible only for their documented support service lines', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $machineOperator = User::factory()->create(['role' => 'staff']);
    $order = createOrderRoutingTestOrder($customer, [
        'service_line' => OrderServiceLine::SewingSublimation->value,
    ]);

    addOrderRoutingTestSpecialty($machineOperator, 'machine_operator');

    $orderService = new OrderService;
    $sewingStaffIds = $orderService
        ->getEligibleStaff(OrderServiceLine::SewingSublimation, $order)
        ->modelKeys();
    $bookBindingStaffIds = $orderService
        ->getEligibleStaff(OrderServiceLine::BookBinding, $order)
        ->modelKeys();

    expect($sewingStaffIds)->toContain($machineOperator->id)
        ->and($bookBindingStaffIds)->not->toContain($machineOperator->id);
});

test('staff acceptance adds the order to assigned orders without changing its status', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $order = createOrderRoutingTestOrder($customer);

    addOrderRoutingTestSpecialty($staff, 'graphic_designer');
    $assignment = (new OrderService)->routeOrder(
        $order,
        $staff,
        OrderServiceLine::GraphicDesign,
    );
    $this->actingAs($staff);

    Livewire::test('pages::staff.orders')
        ->call('acceptTask', $assignment->assignment_id)
        ->assertHasNoErrors()
        ->assertSee('was accepted')
        ->assertSee('Custom poster');

    $this->assertDatabaseHas('order_assignments', [
        'assignment_id' => $assignment->assignment_id,
        'assignment_status' => OrderAssignment::STATUS_ACCEPTED,
        'is_active' => true,
    ]);
    $this->assertDatabaseHas('orders', [
        'order_id' => $order->order_id,
        'status' => 'pending',
    ]);

    expect((new OrderService)->getStaffOrders($staff)->modelKeys())
        ->toContain($order->order_id);
});

test('declined task offers become available for reassignment to another eligible staff member', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $decliningStaff = User::factory()->create(['role' => 'staff']);
    $replacementStaff = User::factory()->create(['role' => 'staff']);
    $order = createOrderRoutingTestOrder($customer);

    addOrderRoutingTestSpecialty($decliningStaff, 'graphic_designer');
    addOrderRoutingTestSpecialty($replacementStaff, 'graphic_designer');
    $orderService = new OrderService;
    $assignment = $orderService->routeOrder(
        $order,
        $decliningStaff,
        OrderServiceLine::GraphicDesign,
    );
    $this->actingAs($decliningStaff);

    Livewire::test('pages::staff.orders')
        ->call('declineTask', $assignment->assignment_id)
        ->assertHasNoErrors()
        ->assertSee('returned for routing');

    $eligibleStaffIds = $orderService
        ->getEligibleStaff(OrderServiceLine::GraphicDesign, $order)
        ->modelKeys();

    expect($eligibleStaffIds)
        ->not->toContain($decliningStaff->id)
        ->toContain($replacementStaff->id);

    $replacementAssignment = $orderService->routeOrder(
        $order,
        $replacementStaff,
        OrderServiceLine::GraphicDesign,
    );

    $this->assertDatabaseHas('order_assignments', [
        'assignment_id' => $assignment->assignment_id,
        'assignment_status' => OrderAssignment::STATUS_DECLINED,
        'is_active' => null,
    ]);
    $this->assertDatabaseHas('order_assignments', [
        'assignment_id' => $replacementAssignment->assignment_id,
        'assignment_status' => OrderAssignment::STATUS_OFFERED,
        'is_active' => true,
    ]);
});

test('staff cannot accept another staff member task offer', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $assignedStaff = User::factory()->create(['role' => 'staff']);
    $otherStaff = User::factory()->create(['role' => 'staff']);
    $order = createOrderRoutingTestOrder($customer);

    addOrderRoutingTestSpecialty($assignedStaff, 'graphic_designer');
    $assignment = (new OrderService)->routeOrder(
        $order,
        $assignedStaff,
        OrderServiceLine::GraphicDesign,
    );
    $this->actingAs($otherStaff);

    Livewire::test('pages::staff.orders')
        ->call('acceptTask', $assignment->assignment_id)
        ->assertForbidden();

    $this->assertDatabaseHas('order_assignments', [
        'assignment_id' => $assignment->assignment_id,
        'staff_id' => $assignedStaff->id,
        'assignment_status' => OrderAssignment::STATUS_OFFERED,
        'is_active' => true,
    ]);
});

test('an order cannot receive a second active assignment', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $firstStaff = User::factory()->create(['role' => 'staff']);
    $secondStaff = User::factory()->create(['role' => 'staff']);
    $order = createOrderRoutingTestOrder($customer);

    addOrderRoutingTestSpecialty($firstStaff, 'graphic_designer');
    addOrderRoutingTestSpecialty($secondStaff, 'graphic_designer');
    $orderService = new OrderService;
    $orderService->routeOrder($order, $firstStaff, OrderServiceLine::GraphicDesign);

    expect(fn () => $orderService->routeOrder($order, $secondStaff, OrderServiceLine::GraphicDesign))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('order_assignments', 1);
    $this->assertDatabaseHas('order_assignments', [
        'order_id' => $order->order_id,
        'staff_id' => $firstStaff->id,
        'is_active' => true,
    ]);
});

test('owners can update specialties while other roles cannot access routing controls', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $staff = User::factory()->create(['role' => 'staff']);
    $this->actingAs($owner);

    Livewire::test('pages::owner.specialties')
        ->set("staffSpecialtySelections.{$staff->id}", ['book_binder'])
        ->call('updateStaffSpecialties', $staff->id)
        ->assertHasNoErrors()
        ->assertSee('Specialties updated');

    $this->assertDatabaseHas('staff_specialties', [
        'staff_id' => $staff->id,
        'specialty' => 'book_binder',
    ]);

    $this->actingAs(User::factory()->create(['role' => 'staff']));

    Livewire::test('pages::owner.specialties')
        ->assertForbidden();
});
