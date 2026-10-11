<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderTotalService;
use App\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

function createPaymentRuleOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
        'status' => 'pending',
    ], $attributes));
}

function confirmPaymentRuleOrderTotal(Order $order, User $cashier, string $total): Order
{
    (new OrderTotalService)->confirmTotal($order, $cashier, $total);

    return $order->fresh();
}

test('a payment is rejected when the order has no confirmed total', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createPaymentRuleOrder($customer);

    expect(fn () => (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'cash',
        '100.00',
        Carbon::parse('2026-10-10 09:00:00'),
    ))->toThrow(ValidationException::class);

    expect(Payment::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe('pending');
});

test('a payment is accepted against a confirmed total', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '1000.00');

    $payment = (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'cash',
        '400.00',
        Carbon::parse('2026-10-10 09:30:00'),
    );

    $this->assertModelExists($payment);

    expect($payment->order_id)->toBe($order->order_id)
        ->and($payment->received)->toBe('400.00')
        ->and((new PaymentService)->getAmountPaid($order->fresh()))->toBe('400.00')
        ->and((new PaymentService)->getRemainingBalance($order->fresh()))->toBe('600.00');
});

test('multiple partial payments accumulate into the correct paid amount and balance', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '1000.00');
    $service = new PaymentService;

    $service->recordPayment($order, $cashier, 'cash', '400.00', Carbon::parse('2026-10-10 09:00:00'));
    $service->recordPayment($order, $cashier, 'gcash', '250.50', Carbon::parse('2026-10-10 10:00:00'));

    $order = $order->fresh();

    expect($service->getAmountPaid($order))->toBe('650.50')
        ->and($service->getRemainingBalance($order))->toBe('349.50')
        ->and($service->getPaymentStatus($order))->toBe(PaymentService::STATUS_PARTIAL)
        ->and($order->payments()->count())->toBe(2);
});

test('the financial payment status is unpaid partial or paid based on recorded payments', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $service = new PaymentService;

    $unpaidOrder = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '500.00');
    $paidOrder = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '500.00');

    expect($service->getPaymentStatus($unpaidOrder))->toBe(PaymentService::STATUS_UNPAID);

    $service->recordPayment($unpaidOrder, $cashier, 'cash', '200.00', Carbon::parse('2026-10-10 09:00:00'));

    expect($service->getPaymentStatus($unpaidOrder->fresh()))->toBe(PaymentService::STATUS_PARTIAL);

    $service->recordPayment($paidOrder, $cashier, 'cash', '500.00', Carbon::parse('2026-10-10 09:00:00'));

    expect($service->getPaymentStatus($paidOrder->fresh()))->toBe(PaymentService::STATUS_PAID);
});

test('a payment that exactly matches the confirmed total marks the order paid', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '999.99');
    $service = new PaymentService;

    $service->recordPayment($order, $cashier, 'bank_transfer', '999.99', Carbon::parse('2026-10-10 09:00:00'));

    expect($service->getAmountPaid($order->fresh()))->toBe('999.99')
        ->and($service->getRemainingBalance($order->fresh()))->toBe('0.00')
        ->and($service->getPaymentStatus($order->fresh()))->toBe(PaymentService::STATUS_PAID);
});

test('payments are rejected when the amount is zero negative malformed or has excessive decimals', function (string $invalidAmount) {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '1000.00');

    expect(fn () => (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'cash',
        $invalidAmount,
        Carbon::parse('2026-10-10 09:00:00'),
    ))->toThrow(ValidationException::class);

    expect(Payment::query()->count())->toBe(0);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0.00'],
    'negative' => ['-25.00'],
    'malformed' => ['abc'],
    'empty string' => [''],
    'excessive decimals' => ['10.999'],
]);

test('a payment exceeding the remaining balance is rejected without capping the amount', function (string $submittedAmount) {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '100.00');
    $service = new PaymentService;

    $service->recordPayment($order, $cashier, 'cash', '80.00', Carbon::parse('2026-10-10 09:00:00'));

    expect(fn () => $service->recordPayment(
        $order,
        $cashier,
        'cash',
        $submittedAmount,
        Carbon::parse('2026-10-10 10:00:00'),
    ))->toThrow(ValidationException::class);

    expect(Payment::query()->count())->toBe(1)
        ->and($service->getAmountPaid($order->fresh()))->toBe('80.00')
        ->and($service->getRemainingBalance($order->fresh()))->toBe('20.00');
})->with([
    'exceeds by one cent' => ['20.01'],
    'exceeds substantially' => ['500.00'],
]);

test('already fully paid orders cannot accept another payment', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '100.00');
    $service = new PaymentService;

    $service->recordPayment($order, $cashier, 'cash', '100.00', Carbon::parse('2026-10-10 09:00:00'));

    expect(fn () => $service->recordPayment(
        $order,
        $cashier,
        'cash',
        '0.01',
        Carbon::parse('2026-10-10 10:00:00'),
    ))->toThrow(ValidationException::class);

    expect(Payment::query()->count())->toBe(1)
        ->and($service->getPaymentStatus($order->fresh()))->toBe(PaymentService::STATUS_PAID);
});

test('users other than cashiers cannot record a payment', function (string $role) {
    $customer = User::factory()->create(['role' => 'customer']);
    $actor = User::factory()->create(['role' => $role]);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '1000.00');

    expect(fn () => (new PaymentService)->recordPayment(
        $order,
        $actor,
        'cash',
        '100.00',
        Carbon::parse('2026-10-10 09:00:00'),
    ))->toThrow(AuthorizationException::class);

    expect(Payment::query()->count())->toBe(0);
})->with([
    'customer' => ['customer'],
    'staff' => ['staff'],
    'owner' => ['owner'],
]);

test('the payment create policy ability is limited to cashiers', function (string $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('create', Payment::class))->toBe($allowed);
})->with([
    'cashier' => ['cashier', true],
    'owner' => ['owner', false],
    'staff' => ['staff', false],
    'customer' => ['customer', false],
]);

test('a rejected payment leaves existing payment records and production status unchanged', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer, ['status' => 'processing']), $cashier, '100.00');
    $service = new PaymentService;

    $existing = $service->recordPayment($order, $cashier, 'cash', '60.00', Carbon::parse('2026-10-10 09:00:00'));
    $originalPayment = Payment::query()->findOrFail($existing->payment_id);

    expect(fn () => $service->recordPayment(
        $order,
        $cashier,
        'gcash',
        '90.00',
        Carbon::parse('2026-10-10 10:00:00'),
    ))->toThrow(ValidationException::class);

    $unchangedPayment = Payment::query()->findOrFail($existing->payment_id);

    expect(Payment::query()->count())->toBe(1)
        ->and($unchangedPayment->payment_method)->toBe($originalPayment->payment_method)
        ->and($unchangedPayment->received)->toBe($originalPayment->received)
        ->and($unchangedPayment->payment_date->toDateTimeString())->toBe($originalPayment->payment_date->toDateTimeString())
        ->and($order->fresh()->status)->toBe('processing')
        ->and($order->fresh()->confirmed_total)->toBe('100.00');
});

test('recording a payment does not change the order production status', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer, ['status' => 'pending']), $cashier, '1000.00');

    (new PaymentService)->recordPayment($order, $cashier, 'cash', '1000.00', Carbon::parse('2026-10-10 09:00:00'));

    expect($order->fresh()->status)->toBe('pending')
        ->and((new PaymentService)->getPaymentStatus($order->fresh()))->toBe(PaymentService::STATUS_PAID);
});

test('payment amount and remaining balance are calculated with decimal-safe arithmetic', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = confirmPaymentRuleOrderTotal(createPaymentRuleOrder($customer), $cashier, '0.30');
    $service = new PaymentService;

    $service->recordPayment($order, $cashier, 'cash', '0.10', Carbon::parse('2026-10-10 09:00:00'));
    $service->recordPayment($order, $cashier, 'cash', '0.20', Carbon::parse('2026-10-10 09:05:00'));

    expect($service->getAmountPaid($order->fresh()))->toBe('0.30')
        ->and($service->getRemainingBalance($order->fresh()))->toBe('0.00')
        ->and($service->getPaymentStatus($order->fresh()))->toBe(PaymentService::STATUS_PAID);
});
