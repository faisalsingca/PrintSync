<?php

use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

function statusPageOrder(User $customer, string $status, string $type): Order
{
    return Order::query()->create([
        'customer_id' => $customer->id,
        'order_date' => '2026-10-08 10:00:00',
        'order_type' => $type,
        'service_line' => 'tarpaulin_printing',
        'quantity' => 3,
        'status' => $status,
    ]);
}

test('the owner sees a card for every status with its count', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    statusPageOrder($customer, 'pending', 'first order');
    statusPageOrder($customer, 'pending', 'second order');
    statusPageOrder($customer, 'completed', 'finished order');

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.orders-status'))
        ->assertOk()
        ->assertSeeInOrder(['Pending', '2', 'In Progress', '0', 'Ready for Pickup', '0', 'Completed', '1']);
});

test('pressing a status card shows the orders in that status with a link to their details', function () {
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Pat Customer']);
    $pending = statusPageOrder($customer, 'pending', 'pending banners');
    statusPageOrder($customer, 'completed', 'finished flyers');

    $this->actingAs(User::factory()->create(['role' => 'owner']));

    Livewire::test('pages::owner.orders-status')
        ->call('selectStatus', 'pending')
        ->assertSet('status', 'pending')
        ->assertSee('pending banners')
        ->assertSee('Pat Customer')
        ->assertSee(route('owner.orders.show', $pending), false)
        ->assertDontSee('finished flyers')
        ->call('selectStatus', 'pending')
        ->assertSet('status', '')
        ->assertSee('finished flyers');
});

test('the status can be opened straight from the link and orders can be searched', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    statusPageOrder($customer, 'processing', 'wedding tarpaulin');
    statusPageOrder($customer, 'processing', 'school flyers');

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.orders-status', ['status' => 'processing']))
        ->assertOk()
        ->assertSee('wedding tarpaulin')
        ->assertSee('school flyers');

    Livewire::withQueryParams(['status' => 'processing'])
        ->test('pages::owner.orders-status')
        ->set('search', 'wedding')
        ->assertSee('wedding tarpaulin')
        ->assertDontSee('school flyers');
});

test('only the owner can open orders by status', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('owner.orders-status'))
        ->assertForbidden();
})->with(['customer', 'staff', 'cashier']);