<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\StaffSpecialty;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function createPolicyTestOrder(User $customer): Order
{
    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'service_line' => 'graphic_design',
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

test('eligible staff can view service-line orders but only the assignee can update them', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $assignedStaff = User::factory()->create(['role' => 'staff']);
    $eligibleStaff = User::factory()->create(['role' => 'staff']);
    $unrelatedStaff = User::factory()->create(['role' => 'staff']);
    $order = createPolicyTestOrder($customer);
    StaffSpecialty::create([
        'staff_id' => $assignedStaff->id,
        'specialty' => 'graphic_designer',
    ]);
    StaffSpecialty::create([
        'staff_id' => $eligibleStaff->id,
        'specialty' => 'graphic_designer',
    ]);
    StaffSpecialty::create([
        'staff_id' => $unrelatedStaff->id,
        'specialty' => 'book_binder',
    ]);

    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $assignedStaff->id,
    ]);

    expect(Gate::forUser($assignedStaff)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($eligibleStaff)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($unrelatedStaff)->allows('view', $order))->toBeFalse()
        ->and(Gate::forUser($assignedStaff)->allows('updateProductionStatus', $order))->toBeTrue()
        ->and(Gate::forUser($eligibleStaff)->allows('updateProductionStatus', $order))->toBeFalse();
});

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
    StaffSpecialty::create([
        'staff_id' => $staff->id,
        'specialty' => 'graphic_designer',
    ]);
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

test('staff, cashiers and owners can view inventory', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewAny', Inventory::class))->toBeTrue();
})->with([
    'staff' => ['staff'],
    'cashier' => ['cashier'],
    'owner' => ['owner'],
]);

test('customers cannot view inventory', function () {
    $user = User::factory()->create(['role' => 'customer']);

    expect(Gate::forUser($user)->allows('viewAny', Inventory::class))->toBeFalse();
});

test('only owners and cashiers can manage inventory and add stock', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('manage', Inventory::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('addStock', Inventory::class))->toBe($allowed);
})->with([
    'cashier' => ['cashier', true],
    'staff' => ['staff', false],
    'owner' => ['owner', true],
    'customer' => ['customer', false],
]);

test('only staff can report low stock to the cashier', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('createLowStockAlert', Inventory::class))->toBe($allowed);
})->with([
    'staff' => ['staff', true],
    'owner' => ['owner', false],
    'customer' => ['customer', false],
    'cashier' => ['cashier', false],
]);
