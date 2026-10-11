<?php

use App\Models\Order;
use App\Models\OrderTotalHistory;
use App\Models\User;
use Illuminate\Support\Carbon;

function createOrderTotalFoundationOrder(User $customer, array $attributes = []): Order
{
    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'order_date' => now(),
        'order_type' => 'poster',
        'quantity' => 1,
        'status' => 'pending',
    ], $attributes));
}

test('a new order starts without a confirmed total or confirmation metadata', function () {
    $customer = User::factory()->create(['role' => 'customer']);

    $order = createOrderTotalFoundationOrder($customer);

    $this->assertModelExists($order);
    expect($order->fresh()->confirmed_total)->toBeNull()
        ->and($order->fresh()->total_confirmed_at)->toBeNull()
        ->and($order->fresh()->total_confirmed_by_id)->toBeNull();
});

test('an order stores the confirmed total with two decimal places and its confirming user', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalFoundationOrder($customer);

    $order->confirmed_total = '1250.75';
    $order->total_confirmed_at = Carbon::parse('2026-10-10 09:30:00');
    $order->total_confirmed_by_id = $cashier->id;
    $order->save();

    $confirmedOrder = $order->fresh();

    expect($confirmedOrder->confirmed_total)->toBe('1250.75')
        ->and($confirmedOrder->total_confirmed_at->toDateTimeString())->toBe('2026-10-10 09:30:00')
        ->and($confirmedOrder->totalConfirmedBy->is($cashier))->toBeTrue();
});

test('an order total history can record an initial confirmation with no previous total', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalFoundationOrder($customer);

    $history = OrderTotalHistory::create([
        'order_id' => $order->order_id,
        'previous_total' => null,
        'new_total' => '300.00',
        'changed_by_id' => $cashier->id,
        'reason' => 'Initial price confirmation.',
    ]);

    $this->assertModelExists($history);
    expect($history->fresh()->previous_total)->toBeNull()
        ->and($history->fresh()->new_total)->toBe('300.00')
        ->and($history->order->is($order))->toBeTrue()
        ->and($history->changedBy->is($cashier))->toBeTrue()
        ->and($order->totalHistories()->pluck('order_total_history_id')->all())
        ->toBe([$history->order_total_history_id]);
});

test('an order total history can record a later price change from a previous total', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $owner = User::factory()->create(['role' => 'owner']);
    $order = createOrderTotalFoundationOrder($customer);

    $history = OrderTotalHistory::create([
        'order_id' => $order->order_id,
        'previous_total' => '300.00',
        'new_total' => '450.50',
        'changed_by_id' => $owner->id,
        'reason' => 'Customer requested premium finishing.',
    ]);

    expect($history->fresh()->previous_total)->toBe('300.00')
        ->and($history->fresh()->new_total)->toBe('450.50')
        ->and($history->fresh()->reason)->toBe('Customer requested premium finishing.');
});

test('the order total history relationships resolve to the correct foreign keys', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $order = createOrderTotalFoundationOrder($customer);

    $history = OrderTotalHistory::create([
        'order_id' => $order->order_id,
        'previous_total' => null,
        'new_total' => '199.99',
        'changed_by_id' => $cashier->id,
    ]);

    expect($order->totalHistories()->count())->toBe(1)
        ->and($order->totalHistories->first()->is($history))->toBeTrue()
        ->and($cashier->orderTotalHistories()->count())->toBe(1)
        ->and($history->order->order_id)->toBe($order->order_id)
        ->and($history->changedBy->id)->toBe($cashier->id);
});
