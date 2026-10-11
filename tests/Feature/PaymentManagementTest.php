<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;

function createPaymentManagementOrder(User $customer, string $orderType = 'posters'): Order
{
    return Order::create([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => $orderType,
        'quantity' => 25,
    ]);
}

function confirmPaymentManagementOrderTotal(Order $order, string $total): Order
{
    $order->confirmed_total = $total;
    $order->total_confirmed_at = now();
    $order->save();

    return $order;
}

test('cashier records an in-person payment through the payment service', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createPaymentManagementOrder($customer, 'business cards');
    confirmPaymentManagementOrderTotal($order, '1000.00');

    $this->actingAs($cashier);

    Livewire::test('pages::cashier.payments')
        ->set('order_id', $order->order_id)
        ->set('payment_method', 'gcash')
        ->set('received', '350.00')
        ->set('payment_date', '2026-10-08T16:30')
        ->set('received_in_person', true)
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSee('Payment recorded successfully.');

    $payment = Payment::query()->sole();

    expect($payment->order_id)->toBe($order->order_id)
        ->and($payment->cashier_id)->toBe($cashier->id)
        ->and($payment->payment_method)->toBe('gcash')
        ->and($payment->received)->toBe('350.00')
        ->and($payment->payment_date->toDateTimeString())->toBe('2026-10-08 16:30:00');
});

test('cashier payment cannot be recorded without confirming it was received in person', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = createPaymentManagementOrder($customer);
    confirmPaymentManagementOrderTotal($order, '1000.00');

    $this->actingAs($cashier);

    Livewire::test('pages::cashier.payments')
        ->set('order_id', $order->order_id)
        ->set('payment_method', 'cash')
        ->set('received', '50.00')
        ->set('payment_date', '2026-10-08T16:30')
        ->call('recordPayment')
        ->assertHasErrors(['received_in_person' => 'accepted']);

    $this->assertDatabaseCount('payments', 0);
});

test('cashier payment management displays own payment details and dashboard summaries', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $anotherCashier = User::factory()->create(['role' => 'cashier']);
    $customer = User::factory()->create(['role' => 'customer']);
    $firstOrder = createPaymentManagementOrder($customer, 'posters');
    $otherOrder = createPaymentManagementOrder($customer, 'flyers');
    confirmPaymentManagementOrderTotal($firstOrder, '1000.00');
    confirmPaymentManagementOrderTotal($otherOrder, '1000.00');
    $paymentService = new PaymentService;

    $paymentService->recordPayment(
        $firstOrder,
        $cashier,
        'bank_transfer',
        '183.74',
        Carbon::parse('2026-10-08 19:00:00'),
    );
    $paymentService->recordPayment(
        $otherOrder,
        $anotherCashier,
        'cash',
        '927.36',
        Carbon::parse('2026-10-08 19:05:00'),
    );

    $this->actingAs($cashier)
        ->get(route('cashier.payments'))
        ->assertOk()
        ->assertSee('Order #'.$firstOrder->order_id)
        ->assertSee('posters')
        ->assertSee('Bank Transfer')
        ->assertSee('183.74')
        ->assertSee('Oct 8, 2026 7:00 PM')
        ->assertDontSee('927.36');

    $this->get(route('cashier.dashboard'))
        ->assertOk()
        ->assertSee('Orders')
        ->assertSee('Payments You Recorded')
        ->assertSee('Amount Received by You')
        ->assertSee('2')
        ->assertSee('1')
        ->assertSee('183.74');
});

test('customers and staff cannot access cashier payment management', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('cashier.payments'))
        ->assertForbidden();
})->with([
    'customer' => 'customer',
    'staff' => 'staff',
]);
