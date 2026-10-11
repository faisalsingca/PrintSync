<?php

use App\Models\Order;
use App\Models\OrderTotalHistory;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderTotalService;
use App\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

function createOrderTotalServiceOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
        'status' => 'pending',
    ], $attributes));
}

test('an authorized cashier confirms the first total and records history with no previous total', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalServiceOrder($customer);

    $history = (new OrderTotalService)->confirmTotal($order, $cashier, '1500.00', 'Initial quotation.');

    $this->assertModelExists($history);

    $confirmedOrder = $order->fresh();

    expect($confirmedOrder->confirmed_total)->toBe('1500.00')
        ->and($confirmedOrder->total_confirmed_by_id)->toBe($cashier->id)
        ->and($confirmedOrder->total_confirmed_at)->not->toBeNull()
        ->and($history->previous_total)->toBeNull()
        ->and($history->new_total)->toBe('1500.00')
        ->and($history->changed_by_id)->toBe($cashier->id)
        ->and($history->reason)->toBe('Initial quotation.')
        ->and($confirmedOrder->totalHistories()->count())->toBe(1);
});

test('an authorized owner can confirm an order total', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $owner = User::factory()->create(['role' => 'owner']);
    $order = createOrderTotalServiceOrder($customer);

    (new OrderTotalService)->confirmTotal($order, $owner, '250.50');

    expect($order->fresh()->confirmed_total)->toBe('250.50')
        ->and($order->fresh()->total_confirmed_by_id)->toBe($owner->id);
});

test('a later confirmation increases the total and records the previous confirmed amount', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalServiceOrder($customer);
    $service = new OrderTotalService;

    $service->confirmTotal($order, $cashier, '300.00');
    $history = $service->confirmTotal($order, $cashier, '450.50', 'Added premium finishing.');

    expect($order->fresh()->confirmed_total)->toBe('450.50')
        ->and($history->previous_total)->toBe('300.00')
        ->and($history->new_total)->toBe('450.50')
        ->and($order->fresh()->totalHistories()->count())->toBe(2);
});

test('confirmation rejects totals that are zero negative malformed or have excessive decimals', function (string $invalidTotal) {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalServiceOrder($customer);

    expect(fn () => (new OrderTotalService)->confirmTotal($order, $cashier, $invalidTotal))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->confirmed_total)->toBeNull()
        ->and($order->fresh()->total_confirmed_at)->toBeNull()
        ->and($order->fresh()->total_confirmed_by_id)->toBeNull()
        ->and($order->fresh()->totalHistories()->count())->toBe(0);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0.00'],
    'negative' => ['-10.00'],
    'malformed' => ['abc'],
    'empty string' => [''],
    'excessive decimals' => ['10.999'],
]);

test('confirmation rejects an unchanged or reduced total once a total exists', function (string $invalidTotal) {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalServiceOrder($customer);
    $service = new OrderTotalService;

    $service->confirmTotal($order, $cashier, '500.00');

    expect(fn () => $service->confirmTotal($order, $cashier, $invalidTotal))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->confirmed_total)->toBe('500.00')
        ->and($order->fresh()->totalHistories()->count())->toBe(1);
})->with([
    'unchanged' => ['500.00'],
    'reduced' => ['499.99'],
]);

test('unauthorized roles cannot confirm an order total', function (string $role) {
    $customer = User::factory()->create(['role' => 'customer']);
    $actor = User::factory()->create(['role' => $role]);
    $order = createOrderTotalServiceOrder($customer);

    expect(fn () => (new OrderTotalService)->confirmTotal($order, $actor, '100.00'))
        ->toThrow(AuthorizationException::class);

    expect($order->fresh()->confirmed_total)->toBeNull()
        ->and($order->fresh()->totalHistories()->count())->toBe(0);
})->with([
    'customer' => ['customer'],
    'staff' => ['staff'],
]);

test('the confirmTotal policy ability is limited to cashiers and owners', function (string $role, bool $allowed) {
    $customer = User::factory()->create(['role' => 'customer']);
    $user = User::factory()->create(['role' => $role]);
    $order = createOrderTotalServiceOrder($customer);

    expect(Gate::forUser($user)->allows('confirmTotal', $order))->toBe($allowed);
})->with([
    'cashier' => ['cashier', true],
    'owner' => ['owner', true],
    'staff' => ['staff', false],
    'customer' => ['customer', false],
]);

test('the order and history roll back together when history creation fails', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalServiceOrder($customer);

    OrderTotalHistory::creating(function (): void {
        throw new RuntimeException('Historical write failed.');
    });

    expect(fn () => (new OrderTotalService)->confirmTotal($order, $cashier, '900.00', null))
        ->toThrow(RuntimeException::class);

    expect($order->fresh()->confirmed_total)->toBeNull()
        ->and($order->fresh()->total_confirmed_at)->toBeNull()
        ->and($order->fresh()->total_confirmed_by_id)->toBeNull()
        ->and(OrderTotalHistory::query()->count())->toBe(0);
});

test('confirming a total leaves production status and existing payment records untouched', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalServiceOrder($customer, ['status' => 'processing']);

    (new OrderTotalService)->confirmTotal($order, $cashier, '750.00');

    $payment = (new PaymentService)->recordPayment(
        $order,
        $cashier,
        'cash',
        '100.00',
        Carbon::parse('2026-10-10 11:00:00'),
    );
    $originalPayment = Payment::query()->findOrFail($payment->payment_id);

    (new OrderTotalService)->confirmTotal($order, $cashier, '900.00');

    $unchangedPayment = Payment::query()->findOrFail($payment->payment_id);

    expect($unchangedPayment->payment_id)->toBe($originalPayment->payment_id)
        ->and($unchangedPayment->order_id)->toBe($originalPayment->order_id)
        ->and($unchangedPayment->cashier_id)->toBe($originalPayment->cashier_id)
        ->and($unchangedPayment->payment_method)->toBe($originalPayment->payment_method)
        ->and($unchangedPayment->received)->toBe($originalPayment->received)
        ->and($unchangedPayment->payment_date->toDateTimeString())->toBe($originalPayment->payment_date->toDateTimeString());

    expect($order->fresh()->status)->toBe('processing')
        ->and($order->fresh()->confirmed_total)->toBe('900.00')
        ->and(Payment::query()->count())->toBe(1);
});
