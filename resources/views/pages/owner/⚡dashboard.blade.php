<?php

use App\Models\Inventory;
use App\Models\Payment;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Owner Dashboard')] class extends Component
{
    public array $orderStatusCounts = [];

    public int $orderCount = 0;

    public int $inventoryCount = 0;

    public int $lowStockAlertCount = 0;

    public int $paymentCount = 0;

    public string $paymentTotal = '0.00';

    public Collection $recentPayments;

    public function mount(
        OrderService $orderService,
        InventoryService $inventoryService,
        PaymentService $paymentService,
    ): void {
        Gate::authorize('viewAny', Inventory::class);
        Gate::authorize('viewAny', Payment::class);

        $this->orderStatusCounts = $orderService->getOrderStatusCounts();
        $this->orderCount = array_sum(array_column($this->orderStatusCounts, 'count'));

        $inventorySummary = $inventoryService->getInventorySummary();
        $this->inventoryCount = $inventorySummary['inventory_count'];
        $this->lowStockAlertCount = $inventorySummary['low_stock_alert_count'];

        $paymentSummary = $paymentService->getPaymentSummary();
        $this->paymentCount = $paymentSummary['count'];
        $this->paymentTotal = $paymentSummary['received'];
        $this->recentPayments = $paymentService->getRecentPayments();
    }
};
?>

<div class="owner-dashboard-page grid gap-8">
    <section class="relative isolate overflow-hidden rounded-[1.6rem] border border-[var(--ps-border-strong)] bg-[radial-gradient(ellipse_at_82%_8%,rgb(184_243_74/13%),transparent_42%),var(--ps-glass)] px-6 py-8 shadow-[var(--ps-shadow)] sm:px-9 sm:py-10">
        <div class="grid gap-2">
            <span class="inline-flex w-fit rounded-full border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-3 py-1 text-xs font-semibold tracking-wide text-[var(--ps-accent)]">
                OWNER WORKSPACE
            </span>
            <h1 class="text-3xl font-semibold tracking-[-0.045em] text-[var(--ps-text)] sm:text-4xl">Owner Dashboard</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">A read-only overview of current orders, inventory alerts, and saved payments.</p>
        </div>
        <a href="{{ route('owner.monitoring') }}" wire:navigate class="ps-button-secondary mt-6 inline-flex w-fit items-center gap-2">
            Open business monitoring <span aria-hidden="true">→</span>
        </a>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Business summary">
        <article class="ps-glass grid gap-2 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">All Orders</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($orderCount) }}</p>
        </article>
        <article class="ps-glass grid gap-2 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Inventory Materials</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($inventoryCount) }}</p>
        </article>
        <article class="ps-glass grid gap-2 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Recorded Low-Stock Alerts</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($lowStockAlertCount) }}</p>
        </article>
        <article class="ps-glass grid gap-2 p-5 sm:p-6">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Saved Payments</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ number_format($paymentCount) }}</p>
            <p class="text-sm text-[var(--ps-muted)]">Total received: {{ $paymentTotal }}</p>
        </article>
    </section>

    <section class="grid gap-4" aria-labelledby="owner-order-status-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="grid gap-1">
                <h2 id="owner-order-status-heading" class="text-xl font-semibold tracking-tight text-[var(--ps-text)]">Orders by Current Status</h2>
                <p class="text-sm text-[var(--ps-muted)]">Counts reflect the status currently saved for each order.</p>
            </div>
        </div>
        @if ($orderStatusCounts === [])
            <div class="ps-glass px-5 py-6 text-sm text-[var(--ps-muted)]">No orders have been recorded.</div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($orderStatusCounts as $statusCount)
                    <article wire:key="owner-dashboard-status-{{ $statusCount['status'] }}" class="ps-glass flex items-center justify-between gap-3 p-5">
                        <h3 class="ps-status-badge">{{ str($statusCount['status'])->replace('_', ' ')->title() }}</h3>
                        <p class="text-2xl font-semibold tabular-nums text-[var(--ps-text)]">{{ number_format($statusCount['count']) }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="owner-recent-payments-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="grid gap-1">
                <h2 id="owner-recent-payments-heading" class="text-lg font-semibold tracking-tight text-[var(--ps-text)]">Recent Saved Payments</h2>
                <p class="text-sm text-[var(--ps-muted)]">Recent records from the payment database.</p>
            </div>
            <a href="{{ route('owner.monitoring') }}" wire:navigate class="ps-button-secondary w-fit">
                View monitoring <span aria-hidden="true">→</span>
            </a>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($recentPayments as $payment)
                <li wire:key="owner-dashboard-payment-{{ $payment->payment_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
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