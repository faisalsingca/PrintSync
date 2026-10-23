<?php

use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cashier Dashboard')] class extends Component
{
    public int $orderCount = 0;

    public int $paymentCount = 0;

    public string $totalReceived = '0.00';

    public Collection $recentPayments;

    public array $inventorySummary = [];

    public function mount(PaymentService $paymentService, InventoryService $inventoryService): void
    {
        $cashier = auth()->user();
        $payments = $paymentService->getCashierPayments($cashier);
        $summary = $paymentService->getCashierPaymentSummary($cashier);

        $this->orderCount = Order::query()->count();
        $this->paymentCount = $summary['count'];
        $this->totalReceived = $summary['received'];
        $this->recentPayments = $payments->take(5);
        $this->inventorySummary = $inventoryService->getInventorySummary();
    }
};
?>

<div class="cashier-dashboard-page grid gap-8">
    <section class="relative isolate overflow-hidden rounded-[1.6rem] border border-[var(--ps-border-strong)] bg-[radial-gradient(ellipse_at_82%_8%,rgb(184_243_74/13%),transparent_42%),var(--ps-glass)] px-6 py-8 shadow-[var(--ps-shadow)] sm:px-9 sm:py-10">
        <div class="grid gap-2">
            <span class="inline-flex w-fit rounded-full border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-3 py-1 text-xs font-semibold tracking-wide text-[var(--ps-accent)]">
                CASHIER WORKSPACE
            </span>
            <h1 class="text-3xl font-semibold tracking-[-0.045em] text-[var(--ps-text)] sm:text-4xl">Cashier Dashboard</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">A snapshot of orders, payments you have recorded, and inventory.</p>
        </div>
    </section>

    <a href="{{ route('cashier.walk-in.create') }}" wire:navigate class="ps-glass flex flex-wrap items-center justify-between gap-3 p-5 transition-colors hover:border-[var(--ps-border-strong)]">
        <span class="grid gap-1">
            <span class="font-medium text-[var(--ps-text)]">Customer ordering in person?</span>
            <span class="text-sm text-[var(--ps-muted)]">Record their order here.</span>
        </span>
        <span class="ps-button-secondary">New walk-in order <span aria-hidden="true">→</span></span>
    </a>

    <section class="grid gap-4 sm:grid-cols-3" aria-label="Payment and order summary">
        <article class="ps-glass grid gap-4 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Orders</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($orderCount) }}</p>
        </article>
        <article class="ps-glass grid gap-4 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Payments You Recorded</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($paymentCount) }}</p>
        </article>
        <article class="ps-glass grid gap-4 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Amount Received by You</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $totalReceived }}</p>
        </article>
    </section>

    <section class="grid gap-4 sm:grid-cols-3" aria-label="Inventory summary">
        <a href="{{ route('cashier.inventory') }}" wire:navigate class="ps-glass grid gap-4 p-5 transition-colors hover:border-[var(--ps-border-strong)] sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Materials</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($inventorySummary['inventory_count'] ?? 0) }}</p>
        </a>
        <a href="{{ route('cashier.low-stock-reports') }}" wire:navigate class="ps-glass grid gap-4 p-5 transition-colors hover:border-[var(--ps-border-strong)] sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Open Low-Stock Reports</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($inventorySummary['open_low_stock_alert_count'] ?? 0) }}</p>
        </a>
        <a href="{{ route('cashier.inventory') }}" wire:navigate class="ps-glass grid gap-4 p-5 transition-colors hover:border-[var(--ps-border-strong)] sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Manage Inventory</h2>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Add, deduct, or remove materials.</p>
        </a>
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="cashier-recent-payments-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="grid gap-1">
                <h2 id="cashier-recent-payments-heading" class="text-lg font-semibold tracking-tight text-[var(--ps-text)]">Recent Payments</h2>
                <p class="text-sm text-[var(--ps-muted)]">Payments recorded by you.</p>
            </div>
            <a href="{{ route('cashier.payments') }}" wire:navigate class="ps-button-secondary w-fit">
                Manage payments <span aria-hidden="true">→</span>
            </a>
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($recentPayments as $payment)
                <li wire:key="cashier-dashboard-payment-{{ $payment->payment_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            Order #{{ $payment->order->order_id }} <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $payment->order->order_type }}</span>
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">
                            {{ str($payment->payment_method)->replace('_', ' ')->title() }}
                            <span class="px-1" aria-hidden="true">·</span>
                            {{ $payment->payment_date->format('M j, Y g:i A') }}
                        </p>
                    </div>
                    <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $payment->received }}</p>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No payments recorded yet.</li>
            @endforelse
        </ul>
    </section>
</div>