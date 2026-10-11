<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('customer can create an order with server-managed values and is redirected to the order list', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    $orderDate = Carbon::parse('2026-10-09 09:30:00');

    $this->actingAs($customer);
    $this->travelTo($orderDate);

    Livewire::test('pages::customer.create-order')
        ->set('service_line', 'graphic_design')
        ->set('description', 'Matte finish, double-sided')
        ->set('quantity', '250')
        ->call('createOrder')
        ->assertHasNoErrors()
        ->assertRedirect(route('customer.orders'));

    $order = Order::query()->sole();

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->order_date->toDateTimeString())->toBe($orderDate->toDateTimeString())
        ->and($order->order_type)->toBe('Graphic design / layout')
        ->and($order->service_line)->toBe('graphic_design')
        ->and($order->description)->toBe('Matte finish, double-sided')
        ->and($order->design_file)->toBeNull()
        ->and($order->quantity)->toBe(250)
        ->and($order->status)->toBe('pending')
        ->and($order->customer_id)->not->toBe($anotherCustomer->id);

    $this->get(route('customer.orders'))
        ->assertOk()
        ->assertSee('Your order has been submitted successfully.');
});

test('customer design files are stored privately with a generated path', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $designFile = UploadedFile::fake()->create('customer-design.pdf', 512, 'application/pdf');

    Storage::fake('local');
    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('order_type', 'Event posters')
        ->set('service_line', 'graphic_design')
        ->set('quantity', 10)
        ->set('design_file', $designFile)
        ->call('createOrder')
        ->assertHasNoErrors()
        ->assertRedirect(route('customer.orders'));

    $storedPath = Order::query()->sole()->design_file;

    expect($storedPath)
        ->toStartWith('order-designs/')
        ->not->toContain('customer-design.pdf');

    Storage::disk('local')->assertExists($storedPath);
});

test('customer order creation reports required and invalid quantity errors without saving', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('quantity', 0)
        ->call('createOrder')
        ->assertHasErrors([
            'order_type' => 'required',
            'service_line' => 'required',
            'quantity' => 'min',
        ])
        ->assertSee('The order type field is required.');

    $this->assertDatabaseCount('orders', 0);
});

test('customer order creation reports invalid order field values', function (string $attribute, mixed $value, string $rule) {
    $customer = User::factory()->create(['role' => 'customer']);
    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('order_type', 'Event posters')
        ->set('service_line', 'graphic_design')
        ->set('description', 'Color on both sides')
        ->set('quantity', 10)
        ->set($attribute, $value)
        ->call('createOrder')
        ->assertHasErrors([$attribute => $rule]);

    $this->assertDatabaseCount('orders', 0);
})->with([
    'description must be a string' => ['description', ['not', 'a', 'string'], 'string'],
    'quantity must be an integer' => ['quantity', '1.5', 'integer'],
]);

test('customer order creation reports an unsupported service line', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('order_type', 'Event posters')
        ->set('service_line', 'unlisted_service')
        ->set('quantity', 10)
        ->call('createOrder')
        ->assertHasErrors(['service_line'])
        ->assertSee('The selected service line is invalid.');

    $this->assertDatabaseCount('orders', 0);
});

test('customer order creation rejects unsupported design file types', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    Storage::fake('local');
    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('order_type', 'Event posters')
        ->set('service_line', 'graphic_design')
        ->set('quantity', 10)
        ->set('design_file', UploadedFile::fake()->create('design.exe', 100, 'application/x-msdownload'))
        ->call('createOrder')
        ->assertHasErrors(['design_file' => 'mimes']);

    $this->assertDatabaseCount('orders', 0);
});

test('customer order creation rejects design files larger than 10 MB', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    Storage::fake('local');
    $this->actingAs($customer);

    Livewire::test('pages::customer.create-order')
        ->set('order_type', 'Event posters')
        ->set('service_line', 'graphic_design')
        ->set('quantity', 10)
        ->set('design_file', UploadedFile::fake()->create('design.pdf', 10241, 'application/pdf'))
        ->call('createOrder')
        ->assertHasErrors(['design_file' => 'max']);

    $this->assertDatabaseCount('orders', 0);
});
