<?php

use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createOrderAttachmentTestOrder(User $customer, string $fileName = 'customer-design.pdf'): Order
{
    $path = UploadedFile::fake()
        ->create($fileName, 1, 'application/pdf')
        ->store('order-designs', 'local');

    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'Business cards',
        'design_file' => $path,
        'quantity' => 100,
        'status' => 'pending',
    ]);
}

test('customers can download their own private design file', function () {
    Storage::fake('local');
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createOrderAttachmentTestOrder($customer);

    $this->actingAs($customer)
        ->get(route('customer.orders.show', $order))
        ->assertOk()
        ->assertSee(route('orders.design-file', $order))
        ->assertDontSee($order->design_file);

    $this->actingAs($customer)
        ->get(route('orders.design-file', $order))
        ->assertOk()
        ->assertDownload("order-{$order->order_id}-design.pdf")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('owners can download a private design file', function () {
    Storage::fake('local');
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createOrderAttachmentTestOrder($customer);
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('orders.design-file', $order))
        ->assertOk()
        ->assertDownload("order-{$order->order_id}-design.pdf");

    $this->get(route('owner.orders.show', $order))
        ->assertOk()
        ->assertSee(route('orders.design-file', $order));
});

test('assigned staff see the design as an inline preview and cannot download it', function () {
    Storage::fake('local');
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createOrderAttachmentTestOrder($customer);
    $staff = User::factory()->create(['role' => 'staff']);
    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
    ]);

    $this->actingAs($staff)
        ->get(route('staff.orders.show', $order))
        ->assertOk()
        ->assertSee('Customer design')
        ->assertSee(route('orders.design-file', $order), false)
        ->assertDontSee('Download design file');

    $response = $this->get(route('orders.design-file', $order))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline');

    $this->withHeaders(['Sec-Fetch-Dest' => 'document'])
        ->get(route('orders.design-file', $order))
        ->assertForbidden();
});

test('customers and unassigned staff cannot download another orders design file', function (string $role) {
    Storage::fake('local');
    $customer = User::factory()->create(['role' => 'customer']);
    $anotherCustomer = User::factory()->create(['role' => 'customer']);
    $order = createOrderAttachmentTestOrder($anotherCustomer);
    $user = User::factory()->create(['role' => $role]);

    if ($role === 'customer') {
        $user = $customer;
    }

    $this->actingAs($user)
        ->get(route('orders.design-file', $order))
        ->assertForbidden();
})->with([
    'another customer' => ['customer'],
    'unassigned staff' => ['staff'],
    'cashier' => ['cashier'],
]);

test('staff who declined an order cannot access its design file', function () {
    Storage::fake('local');
    $customer = User::factory()->create(['role' => 'customer']);
    $staff = User::factory()->create(['role' => 'staff']);
    $order = createOrderAttachmentTestOrder($customer);
    OrderAssignment::create([
        'order_id' => $order->order_id,
        'staff_id' => $staff->id,
        'assignment_status' => OrderAssignment::STATUS_DECLINED,
        'is_active' => null,
    ]);

    $this->actingAs($staff)
        ->get(route('orders.design-file', $order))
        ->assertForbidden();
});

test('private design downloads reject stored paths outside the order design directory', function () {
    Storage::fake('local');
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'Business cards',
        'design_file' => '../private/secret.pdf',
        'quantity' => 100,
        'status' => 'pending',
    ]);

    $this->actingAs($owner)
        ->get(route('orders.design-file', $order))
        ->assertNotFound();
});

test('guests must authenticate before requesting private design files', function () {
    Storage::fake('local');
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createOrderAttachmentTestOrder($customer);

    $this->get(route('orders.design-file', $order))
        ->assertRedirect(route('login'));
});
