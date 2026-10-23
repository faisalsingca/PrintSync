<?php

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\Services\OrderService;
use Livewire\Livewire;

function autoAssignStaff(string $specialty): User
{
    $staff = User::factory()->create(['role' => 'staff']);
    StaffSpecialty::query()->create(['staff_id' => $staff->id, 'specialty' => $specialty]);

    return $staff;
}

function autoAssignOrder(User $customer, string $serviceLine = 'graphic_design', string $status = 'pending'): Order
{
    return Order::query()->create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'Test order',
        'service_line' => $serviceLine,
        'quantity' => 1,
        'status' => $status,
    ]);
}

test('orders for the same service are shared between the two matching staff', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $first = autoAssignStaff('graphic_designer');
    $second = autoAssignStaff('graphic_designer');
    $service = app(OrderService::class);

    $assignedTo = collect(range(1, 4))
        ->map(fn () => $service->autoAssignOrder(autoAssignOrder($customer))->staff_id);

    expect($assignedTo->countBy()->all())->toBe([$first->id => 2, $second->id => 2])
        ->and($assignedTo[0])->not->toBe($assignedTo[1]);

    expect(OrderAssignment::query()->where('assignment_status', 'accepted')->where('is_active', true)->count())->toBe(4);
});

test('auto assignment ignores staff without the matching specialty and does nothing when nobody matches', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $binder = autoAssignStaff('book_binder');
    $service = app(OrderService::class);

    expect($service->autoAssignOrder(autoAssignOrder($customer, 'graphic_design')))->toBeNull();

    $assignment = $service->autoAssignOrder(autoAssignOrder($customer, 'book_binding'));

    expect($assignment->staff_id)->toBe($binder->id);
});

test('an order that already has an active assignment is not assigned twice', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    autoAssignStaff('graphic_designer');
    $order = autoAssignOrder($customer);
    $service = app(OrderService::class);

    expect($service->autoAssignOrder($order))->not->toBeNull()
        ->and($service->autoAssignOrder($order))->toBeNull();
});

test('a new customer order appears in the orders of staff with the matching specialty', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $designer = autoAssignStaff('graphic_designer');

    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('service_line', 'graphic_design')
        ->set('quantity', 5)
        ->call('createOrder')
        ->assertHasNoErrors();

    $order = Order::query()->sole();

    // Orders are shared by specialty, so no individual assignment row is needed.
    expect($order->order_type)->toBe('Graphic design / layout');

    expect(app(OrderService::class)->getStaffOrders($designer)->pluck('order_id')->all())
        ->toBe([$order->order_id]);
});
