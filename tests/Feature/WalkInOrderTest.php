<?php

use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

test('the cashier can create a walk-in order', function () {
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.create-walk-in-order')
        ->set('customer_name', 'Juan Dela Cruz')
        ->set('service_line', 'tarpaulin_printing')
        ->set('quantity', 3)
        ->set('description', '2x3 ft')
        ->call('createWalkInOrder')
        ->assertHasNoErrors();

    $order = Order::query()->firstOrFail();

    expect($order->quantity)->toBe(3)
        ->and($order->status)->toBe('pending')
        ->and($order->service_line)->toBe('tarpaulin_printing')
        ->and($order->customer_id)->toBeNull()
        ->and($order->customer_name)->toBe('Juan Dela Cruz');

    expect(User::query()->where('role', 'customer')->count())->toBe(0);
});

test('walk-in orders require a name, an order type and a valid quantity', function () {
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.create-walk-in-order')
        ->set('quantity', 0)
        ->call('createWalkInOrder')
        ->assertHasErrors(['customer_name', 'service_line', 'quantity']);

    expect(Order::query()->count())->toBe(0);
});

test('cashier can link a walk-in order to an existing customer account', function () {
    $customer = User::factory()->create([
        'name' => 'Maria Customer',
        'email' => 'maria@example.com',
        'phone' => '09171234567',
        'role' => 'customer',
    ]);

    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.create-walk-in-order')
        ->set('customerSearch', '09171234567')
        ->call('selectCustomer', $customer->id)
        ->set('service_line', 'tarpaulin_printing')
        ->set('quantity', 2)
        ->call('createWalkInOrder')
        ->assertHasNoErrors();

    $order = Order::query()->firstOrFail();

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer_name)->toBeNull()
        ->and(User::query()->where('role', 'customer')->count())->toBe(1);
});

test('only the cashier can open the walk-in order page', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('cashier.walk-in.create'))
        ->assertForbidden();
})->with(['customer', 'staff', 'owner']);
