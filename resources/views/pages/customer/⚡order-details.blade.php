<?php

use App\Models\Order;
use App\OrderStatus;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Details')] class extends Component
{
    public Order $order;

    public ?string $confirmedTotal = null;

    public string $amountPaid = '0.00';

    public ?string $remainingBalance = null;

    public ?string $paymentStatus = null;

    public Collection $payments;

    public function mount(Order $order, PaymentService $paymentService): void
    {
        Gate::authorize('view', $order);

        $this->order = $order;
        $this->confirmedTotal = $order->confirmed_total;
        $this->amountPaid = $paymentService->getAmountPaid($order);
        $this->remainingBalance = $paymentService->getRemainingBalance($order);
        $this->paymentStatus = $paymentService->getPaymentStatus($order);
        $this->payments = $paymentService->getOrderPayments($order)->sortBy('payment_date')->values();
    }
};
?>

<div class="customer-order-details-page grid gap-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="grid gap-1">
            <p class="text-sm font-medium text-[var(--ps-muted)]">Order #{{ $order->order_id }}</p>
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Order Details</h1>
        </div>

        <a href="{{ route('customer.orders') }}" wire:navigate class="ps-button-ghost w-fit">
            Back to My Orders
        </a>
    </header>

    <section class="ps-glass grid gap-6 p-5 sm:p-8" aria-labelledby="order-tracking-heading">
        <div class="flex flex-col gap-3 border-b border-[var(--ps-border)] pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid gap-1">
                <h2 id="order-tracking-heading" class="text-xl font-semibold capitalize text-[var(--ps-text)]">{{ $order->order_type }}</h2>
                <p class="text-sm text-[var(--ps-muted)]">Current order status</p>
            </div>
            <x-status-badge :tone="OrderStatus::toneFor($order->status)" :label="OrderStatus::labelFor($order->status)" />
        </div>

        <dl class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-1">
                <dt class="text-sm text-[var(--ps-muted)]">Quantity</dt>
                <dd class="font-medium text-[var(--ps-text)]">{{ number_format($order->quantity) }} {{ str('copy')->plural($order->quantity) }}</dd>
            </div>
            <div class="grid gap-1">
                <dt class="text-sm text-[var(--ps-muted)]">Submitted</dt>
                <dd class="font-medium text-[var(--ps-text)]">{{ $order->order_date->format('M j, Y \a\t g:i A') }}</dd>
            </div>
            <div class="grid gap-1 sm:col-span-2">
                <dt class="text-sm text-[var(--ps-muted)]">Description</dt>
                <dd class="whitespace-pre-line text-[var(--ps-text)]">{{ $order->description ?: 'No description provided.' }}</dd>
            </div>
            <div class="grid gap-1 sm:col-span-2">
                <dt class="text-sm text-[var(--ps-muted)]">Design file</dt>
                <dd class="text-[var(--ps-text)]">
                    @if ($order->design_file)
                        <a href="{{ route('orders.design-file', $order) }}" class="text-[var(--ps-accent)] underline underline-offset-4">Download design file</a>
                    @else
                        No design file attached.
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="ps-glass grid gap-6 p-5 sm:p-8" aria-labelledby="order-payment-heading">
        <div class="flex flex-col gap-3 border-b border-[var(--ps-border)] pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid gap-1">
                <h2 id="order-payment-heading" class="text-xl font-semibold text-[var(--ps-text)]">Payment</h2>
                <p class="text-sm text-[var(--ps-muted)]">Set by the cashier. Pay at the counter.</p>
            </div>
            <x-status-badge
                :tone="match ($paymentStatus) { 'paid' => 'success', 'partial' => 'warning', 'unpaid' => 'danger', default => 'neutral' }"
                :label="match ($paymentStatus) { 'paid' => 'Paid', 'partial' => 'Partially paid', 'unpaid' => 'Unpaid', default => 'Waiting for total' }"
            />
        </div>

        @if ($confirmedTotal === null)
            <p class="text-sm text-[var(--ps-muted)]">The cashier hasn't set the total for this order yet. It will appear here once it is set.</p>
        @else
            <dl class="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-4">
                <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                    <dt class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Order total</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-[var(--ps-text)]">₱{{ number_format((float) $confirmedTotal, 2) }}</dd>
                </div>
                <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                    <dt class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Amount paid</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-[var(--ps-text)]">₱{{ number_format((float) $amountPaid, 2) }}</dd>
                </div>
                <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                    <dt class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Balance left</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-[var(--ps-text)]">₱{{ number_format((float) ($remainingBalance ?? 0), 2) }}</dd>
                </div>
            </dl>

            @if ($payments->isNotEmpty())
                <ul class="grid gap-2 rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] px-4 py-3 text-sm text-[var(--ps-muted)]">
                    @foreach ($payments as $payment)
                        <li wire:key="customer-payment-{{ $payment->payment_id }}" class="flex flex-wrap justify-between gap-2">
                            <span>
                                {{ str($payment->payment_method)->replace('_', ' ')->title()->replace('Gcash', 'GCash') }}
                                · {{ $payment->payment_date->format('M j, Y g:i A') }}
                            </span>
                            <span class="font-medium tabular-nums text-[var(--ps-text)]">₱{{ number_format((float) $payment->received, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>
</div>