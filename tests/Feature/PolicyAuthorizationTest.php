<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function createPolicyTestOrder(User $customer): Order
{
    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
    ]);
}

test('only customers can create orders', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('create', Order::class))->toBe($allowed);
})->with([
    'customer' => ['customer', true],
    'staff' => ['staff', false],
    'cashier' => ['cashier', false],
    'owner' => ['owner', false],
]);

test('customers can view only their own orders', function (bool $isOwner) {
    $customer = User::factory()->create(['role' => 'customer']);
    $orderCustomer = $isOwner
        ? $customer
        : User::factory()->create(['role' => 'customer']);
    $order = createPolicyTestOrder($orderCustomer);

    expect(Gate::forUser($customer)->allows('view', $order))->toBe($isOwner);
})->with([
    'their own order' => [true],
    'another customer order' => [false],
]);

test('only assigned staff can view the order', function (bool $isAssigned) {
    $customer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $order = createPolicyTestOrder($customer);
    $assignedStaff = $isAssigned ? $staff : User::factory()->create(['role' => 'staff']);

    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $assignedStaff->id,
    ]);

    expect(Gate::forUser($staff)->allows('view', $order))->toBe($isAssigned);
})->with([
    'assigned staff member' => [true],
    'another staff member' => [false],
]);

test('only owners can route orders and manage staff specialties', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createPolicyTestOrder($customer);

    expect(Gate::forUser($user)->allows('route', $order))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('manageStaffSpecialties', Order::class))->toBe($allowed);
})->with([
    'owner' => ['owner', true],
    'staff' => ['staff', false],
    'customer' => ['customer', false],
    'cashier' => ['cashier', false],
]);

test('only the assignee can respond to an active offered task', function (string $role, bool $isAssignee, bool $allowed) {
    $customer = User::factory()->create(['role' => 'customer']);
    $user = User::factory()->create(['role' => $role]);
    $staff = $isAssignee ? $user : User::factory()->create(['role' => 'staff']);
    $order = createPolicyTestOrder($customer);
    $assignment = OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
        'assignment_status' => OrderAssignment::STATUS_OFFERED,
        'is_active' => true,
    ]);

    expect(Gate::forUser($user)->allows('respondToAssignment', [$order, $assignment]))->toBe($allowed);
})->with([
    'assigned staff member' => ['staff', true, true],
    'another staff member' => ['staff', false, false],
    'owner' => ['owner', false, false],
    'customer' => ['customer', false, false],
    'cashier' => ['cashier', false, false],
]);

test('cashiers and owners can view orders', function (string $role) {
    $customer = User::factory()->create(['role' => 'customer']);
    $user = User::factory()->create(['role' => $role]);
    $order = createPolicyTestOrder($customer);

    expect(Gate::forUser($user)->allows('view', $order))->toBeTrue();
})->with([
    'cashier' => ['cashier'],
    'owner' => ['owner'],
]);

test('staff and owners can view inventory', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewAny', Inventory::class))->toBeTrue();
})->with([
    'staff' => ['staff'],
    'owner' => ['owner'],
]);

test('customers and cashiers cannot view inventory', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewAny', Inventory::class))->toBeFalse();
})->with([
    'customer' => ['customer'],
    'cashier' => ['cashier'],
]);

test('only staff can add stock', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('addStock', Inventory::class))->toBe($allowed);
})->with([
    'staff' => ['staff', true],
    'owner' => ['owner', false],
    'customer' => ['customer', false],
    'cashier' => ['cashier', false],
]);

test('only staff can create low-stock alerts', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('createLowStockAlert', Inventory::class))->toBe($allowed);
})->with([
    'staff' => ['staff', true],
    'owner' => ['owner', false],
    'customer' => ['customer', false],
    'cashier' => ['cashier', false],
]);

test('cashiers and owners can view payments', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewAny', Payment::class))->toBeTrue();
})->with([
    'cashier' => ['cashier'],
    'owner' => ['owner'],
]);

test('customers and staff cannot view payments', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewAny', Payment::class))->toBeFalse();
})->with([
    'customer' => ['customer'],
    'staff' => ['staff'],
]);

test('only cashiers can create payments', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('create', Payment::class))->toBe($allowed);
})->with([
    'cashier' => ['cashier', true],
    'owner' => ['owner', false],
    'customer' => ['customer', false],
    'staff' => ['staff', false],
]);
