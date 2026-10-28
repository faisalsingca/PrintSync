<?php

use App\Models\Order;
use App\Models\User;

function createCustomerDashboardOrder(User $customer, string $orderType, string $status, string $date, int $quantity = 1): Order
{
    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => $date,
        'order_type' => $orderType,
        'quantity' => $quantity,
        'status' => $status,
    ]);
}

test('customer dashboard shows the authenticated customer order summary and recent orders only', function () {
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Morgan Print']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    $recentOrder = createCustomerDashboardOrder($customer, 'business cards', 'processing', '2026-10-08 10:00:00', 250);
    createCustomerDashboardOrder($customer, 'flyers', 'pending', '2026-10-07 10:00:00');
    createCustomerDashboardOrder($customer, 'posters', 'completed', '2026-10-06 10:00:00');
    createCustomerDashboardOrder($customer, 'cancelled order', 'cancelled', '2026-10-05 10:00:00');
    createCustomerDashboardOrder($anotherCustomer, 'private order', 'pending', '2026-10-09 10:00:00');

    $this->actingAs($customer)
        ->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee('Welcome, Morgan Print')
        ->assertSee('Create New Order')
        ->assertSee(route('customer.orders.create'))
        ->assertSee('Active Orders')
        ->assertSee('Pending Orders')
        ->assertSee('Completed Orders')
        ->assertSeeInOrder(['Active Orders', '2', 'Pending Orders', '1', 'Completed Orders', '1'])
        ->assertSee('business cards')
        ->assertSee('#'.$recentOrder->order_id)
        ->assertSee('250 copies')
        ->assertSee('Oct 8, 2026')
        ->assertDontSee('private order');
});

test('customer dashboard shows an empty state when the customer has no orders', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    createCustomerDashboardOrder($anotherCustomer, 'another customer order', 'pending', '2026-10-08 10:00:00');

    $this->actingAs($customer)
        ->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee('Your next great print starts here.')
        ->assertSee('You don\'t have any orders yet.', false)
        ->assertDontSee('another customer order');
});
