<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

function createCustomerOrderDetailsOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => Carbon::parse('2026-10-08 10:15:00'),
        'order_type' => 'business cards',
        'description' => 'Matte finish, double-sided',
        'quantity' => 250,
        'status' => 'processing',
    ], $attributes));
}

test('customer orders list displays order summary and links to its details', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createCustomerOrderDetailsOrder($customer);

    $this->actingAs($customer)
        ->get(route('customer.orders'))
        ->assertOk()
        ->assertSee('#'.$order->order_id)
        ->assertSee('business cards')
        ->assertSee('250 copies')
        ->assertSee('Oct 8, 2026')
        ->assertSee('In Progress')
        ->assertSee(route('customer.orders.show', $order));
});

test('customer can view the saved details and current status of their order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createCustomerOrderDetailsOrder($customer, ['design_file' => 'order-designs/private-file.pdf']);

    $this->actingAs($customer)
        ->get(route('customer.orders.show', $order))
        ->assertOk()
        ->assertSee('#'.$order->order_id)
        ->assertSee('business cards')
        ->assertSee('250 copies')
        ->assertSee('Oct 8, 2026 at 10:15 AM')
        ->assertSee('Matte finish, double-sided')
        ->assertSee('In Progress')
        ->assertSee('Download design file')
        ->assertDontSee('private-file.pdf');
});

test('customer receives forbidden when viewing another customers order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    $order = createCustomerOrderDetailsOrder($anotherCustomer);

    $this->actingAs($customer)
        ->get(route('customer.orders.show', $order))
        ->assertForbidden();
});

test('non-customers cannot access the customer order details route', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createCustomerOrderDetailsOrder($customer);

    $this->actingAs($user)
        ->get(route('customer.orders.show', $order))
        ->assertForbidden();
})->with([
    'staff' => ['staff'],
    'cashier' => ['cashier'],
    'owner' => ['owner'],
]);
