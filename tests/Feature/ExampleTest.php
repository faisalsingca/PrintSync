<?php

use App\Models\User;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('Print projects,')
        ->assertSee('Attach your design')
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false);
});

test('authenticated customers can navigate to their order features from home', function () {
    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($customer)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('customer.orders.create'), false)
        ->assertSee(route('customer.orders'), false);
});

test('authenticated non-customer roles are directed to their own workspace', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('dashboard'), false)
        ->assertDontSee(route('customer.orders.create'), false);
})->with([
    'staff' => 'staff',
    'cashier' => 'cashier',
    'owner' => 'owner',
]);
