<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Payment;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reports')] class extends Component
{
    public string $fromDate = '';

    public string $toDate = '';

    public string $activeReport = 'sales';

    public function mount(): void
    {
        Gate::authorize('viewAny', Payment::class);
        Gate::authorize('viewAny', Inventory::class);

        $this->fromDate = now()->startOfMonth()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
    }

    public function selectReport(string $report): void
    {
        Gate::authorize('viewAny', Payment::class);

        if (! in_array($report, ['sales', 'inventory', 'activity'], true)) {
            throw ValidationException::withMessages(['activeReport' => 'Select an available report.']);
        }

        $this->activeReport = $report;
    }

    public function applyFilters(): void
    {
        Gate::authorize('viewAny', Payment::class);

        $this->validate([
            'fromDate' => ['required', 'date', 'before_or_equal:toDate'],
            'toDate' => ['required', 'date', 'after_or_equal:fromDate', 'before_or_equal:today'],
        ]);

        if (Carbon::parse($this->fromDate)->diffInDays(Carbon::parse($this->toDate)) > 366) {
            throw ValidationException::withMessages(['fromDate' => 'Choose a date range of one year or less.']);
        }
    }

    #[Computed]
    public function salesAndPayments(): array
    {
        Gate::authorize('viewAny', Payment::class);

        if (! $this->hasValidDateRange()) {
            return [
                'order_count' => 0,
                'confirmed_sales' => '0.00',
                'payment_count' => 0,
                'payment_total' => '0.00',
                'payment_methods' => new \Illuminate\Database\Eloquent\Collection,
                'payments' => new \Illuminate\Database\Eloquent\Collection,
            ];
        }

        return app(ReportService::class)->salesAndPayments(
            Carbon::parse($this->fromDate),
            Carbon::parse($this->toDate),
        );
    }

    #[Computed]
    public function inventory(): \Illuminate\Database\Eloquent\Collection
    {
        Gate::authorize('viewAny', Inventory::class);

        return app(ReportService::class)->inventory();
    }

    #[Computed]
    public function staffActivity(): \Illuminate\Database\Eloquent\Collection
    {
        Gate::authorize('viewAny', AuditLog::class);

        if (! $this->hasValidDateRange()) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return app(ReportService::class)->staffActivity(
            Carbon::parse($this->fromDate),
            Carbon::parse($this->toDate),
        );
    }

    private function hasValidDateRange(): bool
    {
        return Carbon::hasFormat($this->fromDate, 'Y-m-d')
            && Carbon::hasFormat($this->toDate, 'Y-m-d')
            && $this->fromDate <= $this->toDate
            && $this->toDate <= now()->format('Y-m-d')
            && Carbon::parse($this->fromDate)->diffInDays(Carbon::parse($this->toDate)) <= 366;
    }
};
?>

<div class="owner-reports-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Reports</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Sales and payments, inventory status, and staff activity.</p>
        </div>
        <button type="button" x-on:click="window.print()" class="ps-button-primary print:hidden">
            <flux:icon.printer class="size-4" /> Print / Save as PDF
        </button>
    </header>

    <section class="ps-glass grid gap-5 p-5 sm:p-6 print:hidden" aria-label="Report controls">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Report type">
            <button type="button" role="tab" aria-selected="{{ $activeReport === 'sales' ? 'true' : 'false' }}" wire:click="selectReport('sales')" class="ps-button-secondary">Sales &amp; Payments</button>
            <button type="button" role="tab" aria-selected="{{ $activeReport === 'inventory' ? 'true' : 'false' }}" wire:click="selectReport('inventory')" class="ps-button-secondary">Inventory</button>
            <button type="button" role="tab" aria-selected="{{ $activeReport === 'activity' ? 'true' : 'false' }}" wire:click="selectReport('activity')" class="ps-button-secondary">Staff Activity</button>
        </div>

        <form wire:submit="applyFilters" class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div class="grid gap-1">
                <label for="report-from" class="text-sm font-medium text-[var(--ps-text)]">From</label>
                <input id="report-from" type="date" wire:model="fromDate" class="ps-input" required>
                @error('fromDate') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-1">
                <label for="report-to" class="text-sm font-medium text-[var(--ps-text)]">To</label>
                <input id="report-to" type="date" wire:model="toDate" class="ps-input" required>
                @error('toDate') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="ps-button-secondary">Apply dates</button>
        </form>
    </section>

    @if ($activeReport === 'sales')
        @php($report = $this->salesAndPayments)
        <section class="grid gap-5" aria-labelledby="sales-report-heading">
            <header class="grid gap-1">
                <h2 id="sales-report-heading" class="text-2xl font-semibold text-[var(--ps-text)]">Sales &amp; Payments</h2>
                <p class="text-sm text-[var(--ps-muted)]">{{ $fromDate }} through {{ $toDate }} · Sales use the date an order total was confirmed.</p>
            </header>
            <div class="grid gap-3 sm:grid-cols-2">
                <article class="ps-glass grid gap-1 p-4"><h3 class="text-sm text-[var(--ps-muted)]">Confirmed order totals</h3><p class="text-2xl font-semibold text-[var(--ps-text)]">₱{{ number_format((float) $report['confirmed_sales'], 2) }}</p><p class="text-xs text-[var(--ps-muted)]">{{ number_format($report['order_count']) }} orders</p></article>
                <article class="ps-glass grid gap-1 p-4"><h3 class="text-sm text-[var(--ps-muted)]">Payments received</h3><p class="text-2xl font-semibold text-[var(--ps-text)]">₱{{ number_format((float) $report['payment_total'], 2) }}</p><p class="text-xs text-[var(--ps-muted)]">{{ number_format($report['payment_count']) }} payments</p></article>
            </div>
            @if ($report['payment_methods']->isNotEmpty())
                <p class="text-sm text-[var(--ps-muted)]"><span class="font-semibold text-[var(--ps-text)]">By method:</span>
                    @foreach ($report['payment_methods'] as $methodSummary)
                        @if (! $loop->first) · @endif
                        {{ str($methodSummary->payment_method)->replace('_', ' ')->title() }}: ₱{{ number_format((float) $methodSummary->payment_total, 2) }} ({{ $methodSummary->payment_count }})
                    @endforeach
                </p>
            @endif
            <div class="ps-glass overflow-x-auto">
                <table class="w-full min-w-[42rem] text-left text-sm">
                    <thead class="border-b border-[var(--ps-border)] text-[var(--ps-muted)]"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Order</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Cashier</th><th class="px-4 py-3 text-right">Received</th></tr></thead>
                    <tbody class="divide-y divide-[var(--ps-border)]">
                        @forelse ($report['payments'] as $payment)
                            <tr wire:key="report-payment-{{ $payment->payment_id }}"><td class="px-4 py-3">{{ $payment->payment_date->format('M j, Y g:i A') }}</td><td class="px-4 py-3">#{{ $payment->order_id }}</td><td class="px-4 py-3">{{ $payment->order->customerDisplayName() }}</td><td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $payment->cashier?->name ?? 'Unknown' }}</td><td class="px-4 py-3 text-right">₱{{ number_format((float) $payment->received, 2) }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-[var(--ps-muted)]">No payments in this date range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif ($activeReport === 'inventory')
        @php($inventoryItems = $this->inventory)
        <section class="grid gap-5" aria-labelledby="inventory-report-heading">
            <header class="grid gap-1"><h2 id="inventory-report-heading" class="text-2xl font-semibold text-[var(--ps-text)]">Inventory</h2><p class="text-sm text-[var(--ps-muted)]">Current stock levels, low-stock items, and archived materials as of {{ now()->format('M j, Y g:i A') }}.</p></header>
            <div class="grid gap-3 sm:grid-cols-4">
                <article class="ps-glass grid gap-1 p-4"><h3 class="text-sm text-[var(--ps-muted)]">Active materials</h3><p class="text-2xl font-semibold">{{ $inventoryItems->whereNull('archived_at')->count() }}</p></article>
                <article class="ps-glass grid gap-1 p-4"><h3 class="text-sm text-[var(--ps-muted)]">Low / out of stock</h3><p class="text-2xl font-semibold">{{ $inventoryItems->filter(fn ($item) => ! $item->isArchived() && $item->stockStatus() !== Inventory::STATUS_IN_STOCK)->count() }}</p></article>
                <article class="ps-glass grid gap-1 p-4"><h3 class="text-sm text-[var(--ps-muted)]">Archived</h3><p class="text-2xl font-semibold">{{ $inventoryItems->filter(fn ($item) => $item->isArchived())->count() }}</p></article>
                <article class="ps-glass grid gap-1 p-4"><h3 class="text-sm text-[var(--ps-muted)]">Total materials</h3><p class="text-2xl font-semibold">{{ $inventoryItems->count() }}</p></article>
            </div>
            <div class="ps-glass overflow-x-auto">
                <table class="w-full min-w-[38rem] text-left text-sm"><thead class="border-b border-[var(--ps-border)] text-[var(--ps-muted)]"><tr><th class="px-4 py-3">Material</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Quantity</th><th class="px-4 py-3 text-right">Low-stock level</th></tr></thead><tbody class="divide-y divide-[var(--ps-border)]">
                    @forelse ($inventoryItems as $item)
                        <tr wire:key="report-inventory-{{ $item->inventory_id }}"><td class="px-4 py-3 font-medium">{{ $item->material_name }}</td><td class="px-4 py-3">{{ $item->isArchived() ? 'Archived' : $item->stockStatusLabel() }}</td><td class="px-4 py-3 text-right">{{ $item->quantity }} {{ $item->unit }}</td><td class="px-4 py-3 text-right">{{ $item->low_stock_level }} {{ $item->unit }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-[var(--ps-muted)]">No inventory records.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
        </section>
    @else
        <section class="grid gap-5" aria-labelledby="staff-activity-report-heading">
            <header class="grid gap-1"><h2 id="staff-activity-report-heading" class="text-2xl font-semibold text-[var(--ps-text)]">Staff Activity</h2><p class="text-sm text-[var(--ps-muted)]">Recorded staff and cashier actions from {{ $fromDate }} through {{ $toDate }}.</p></header>
            <div class="ps-glass overflow-x-auto">
                <table class="w-full min-w-[42rem] text-left text-sm"><thead class="border-b border-[var(--ps-border)] text-[var(--ps-muted)]"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Person</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Details</th></tr></thead><tbody class="divide-y divide-[var(--ps-border)]">
                    @forelse ($this->staffActivity as $log)
                        <tr wire:key="report-activity-{{ $log->id }}"><td class="px-4 py-3">{{ $log->created_at->format('M j, Y g:i A') }}</td><td class="px-4 py-3">{{ $log->actor_name }}</td><td class="px-4 py-3">{{ $log->actionLabel() }}</td><td class="px-4 py-3">{{ $log->description }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-[var(--ps-muted)]">No staff activity is recorded for this date range.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
        </section>
    @endif
</div>