<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create(['role' => 'customer']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirectToRoute('customer.dashboard');
});

test('users are redirected to the dashboard matching their role', function (string $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirectToRoute($routeName);
})->with([
    'customer' => ['customer', 'customer.dashboard'],
    'staff' => ['staff', 'staff.dashboard'],
    'cashier' => ['cashier', 'cashier.dashboard'],
    'owner' => ['owner', 'owner.dashboard'],
]);

test('users can access the dashboard matching their role', function (string $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route($routeName));

    $response->assertOk();
})->with([
    'customer' => ['customer', 'customer.dashboard'],
    'staff' => ['staff', 'staff.dashboard'],
    'cashier' => ['cashier', 'cashier.dashboard'],
    'owner' => ['owner', 'owner.dashboard'],
]);

test('users receive forbidden when accessing another role dashboard', function (string $role, string $routeName) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route($routeName));

    $response->assertForbidden();
})->with([
    'customer cannot access staff dashboard' => ['customer', 'staff.dashboard'],
    'customer cannot access cashier dashboard' => ['customer', 'cashier.dashboard'],
    'customer cannot access owner dashboard' => ['customer', 'owner.dashboard'],
    'staff cannot access customer dashboard' => ['staff', 'customer.dashboard'],
    'staff cannot access cashier dashboard' => ['staff', 'cashier.dashboard'],
    'staff cannot access owner dashboard' => ['staff', 'owner.dashboard'],
    'cashier cannot access customer dashboard' => ['cashier', 'customer.dashboard'],
    'cashier cannot access staff dashboard' => ['cashier', 'staff.dashboard'],
    'cashier cannot access owner dashboard' => ['cashier', 'owner.dashboard'],
    'owner cannot access customer dashboard' => ['owner', 'customer.dashboard'],
    'owner cannot access staff dashboard' => ['owner', 'staff.dashboard'],
    'owner cannot access cashier dashboard' => ['owner', 'cashier.dashboard'],
]);

test('guests are redirected to login when requesting a role dashboard', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertRedirect(route('login'));
})->with([
    'customer dashboard' => ['customer.dashboard'],
    'staff dashboard' => ['staff.dashboard'],
    'cashier dashboard' => ['cashier.dashboard'],
    'owner dashboard' => ['owner.dashboard'],
]);
