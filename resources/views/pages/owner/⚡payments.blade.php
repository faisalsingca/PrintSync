<?php

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Saved Payments')] class extends Component
{
    public int $paymentCount = 0;

    public string $paymentTotal = '0.00';

    public Collection $recentPayments;

    public function mount(PaymentService $paymentService): void
    {
        Gate::authorize('viewAny', Payment::class);

        $summary = $paymentService->getPaymentSummary();
        $this->paymentCount = $summary['count'];
        $this->paymentTotal = $summary['received'];
        $this->recentPayments = $paymentService->getRecentPayments();
    }
};
?>

<div class="owner-payments-page grid gap-8">
    <header class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Saved Payments</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Totals and recent records from the payments currently stored.</p>
        </div>
        <dl class="grid gap-1 sm:text-right">
            <dt class="text-sm text-[var(--ps-muted)]">Total received ({{ number_format($paymentCount) }} {{ str('payment')->plural($paymentCount) }})</dt>
            <dd class="text-2xl font-semibold tabular-nums text-[var(--ps-text)]">{{ $paymentTotal }}</dd>
        </dl>
    </header>

    <section class="ps-glass overflow-hidden" aria-label="Recent payments">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($recentPayments as $payment)
                <li wire:key="owner-payment-{{ $payment->payment_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            Order #{{ $payment->order->order_id }}
                            <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $payment->order->order_type }}</span>
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">
                            {{ str($payment->payment_method)->replace('_', ' ')->title()->replace('Gcash', 'GCash') }}
                            <span class="px-1" aria-hidden="true">·</span>
                            {{ $payment->payment_date->format('M j, Y g:i A') }}
                        </p>
                    </div>
                    <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $payment->received }}</p>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No payments have been recorded.</li>
            @endforelse
        </ul>
    </section>
</div>