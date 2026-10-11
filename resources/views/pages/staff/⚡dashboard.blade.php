<?php

use App\OrderStatus;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff Dashboard')] class extends Component
{
    public int $assignedOrderCount = 0;

    public array $statusCounts = [];

    public Collection $activeOrders;

    public bool $isInventoryClerk = false;

    public array $inventorySummary = [];

    public function mount(OrderService $orderService, InventoryService $inventoryService): void
    {
        $user = auth()->user();
        $orders = $orderService->getStaffOrders($user);

        $this->assignedOrderCount = $orders->count();

        // Every status always gets a card, even when its count is 0.
        $counts = $orders->countBy('status');
        foreach (OrderStatus::cases() as $status) {
            $this->statusCounts[$status->value] = (int) $counts->get($status->value, 0);
        }

        $this->activeOrders = $orders
            ->where('status', '!=', OrderStatus::Completed->value)
            ->sortByDesc('order_date')
            ->take(5)
            ->values();

        $this->isInventoryClerk = $user->isInventoryClerk();

        if ($this->isInventoryClerk) {
            $this->inventorySummary = $inventoryService->getInventorySummary();
        }
    }
};
?>

<div class="staff-dashboard-page grid gap-8">
    <section class="relative isolate overflow-hidden rounded-[1.6rem] border border-[var(--ps-border-strong)] bg-[radial-gradient(ellipse_at_82%_8%,rgb(184_243_74/13%),transparent_42%),var(--ps-glass)] px-6 py-8 shadow-[var(--ps-shadow)] sm:px-9 sm:py-10">
        <div class="grid gap-2">
            <span class="inline-flex w-fit rounded-full border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-3 py-1 text-xs font-semibold tracking-wide text-[var(--ps-accent)]">
                {{ $isInventoryClerk ? 'INVENTORY WORKSPACE' : 'STAFF WORKSPACE' }}
            </span>
            <h1 class="text-3xl font-semibold tracking-[-0.045em] text-[var(--ps-text)] sm:text-4xl">Staff Dashboard</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">
                {{ $isInventoryClerk ? 'Your inventory summary, plus any orders assigned to you.' : 'A summary of the orders assigned to you. Tap a card to see those orders.' }}
            </p>
        </div>
    </section>

    @if ($isInventoryClerk)
        <section class="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-4" aria-label="Inventory summary">
            <a href="{{ route('staff.inventory') }}" wire:navigate class="ps-glass grid gap-3 p-5 transition-colors hover:border-[var(--ps-border-strong)]">
                <h2 class="text-sm font-medium text-[var(--ps-muted)]">Materials</h2>
                <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $inventorySummary['inventory_count'] ?? 0 }}</p>
            </a>
            <a href="{{ route('staff.inventory') }}" wire:navigate class="ps-glass grid gap-3 p-5 transition-colors hover:border-[var(--ps-border-strong)]">
                <h2 class="text-sm font-medium text-[var(--ps-muted)]">Low-stock alerts</h2>
                <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $inventorySummary['low_stock_alert_count'] ?? 0 }}</p>
            </a>
        </section>
    @endif

    <section class="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-4" aria-label="Assigned order counts by current status">
        <a href="{{ route('staff.orders') }}" wire:navigate class="ps-glass grid gap-3 p-5 transition-colors hover:border-[var(--ps-border-strong)]">
            <h2 class="text-sm font-medium text-[var(--ps-muted)]">Assigned Orders</h2>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $assignedOrderCount }}</p>
        </a>

        @foreach ($statusCounts as $status => $count)
            <a
                wire:key="staff-order-status-{{ $status }}"
                href="{{ route('staff.orders', ['status' => $status]) }}"
                wire:navigate
                class="ps-glass grid gap-3 p-5 transition-colors hover:border-[var(--ps-border-strong)]"
            >
                <h2 class="text-sm font-medium text-[var(--ps-muted)]">{{ OrderStatus::labelFor($status) }}</h2>
                <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $count }}</p>
            </a>
        @endforeach
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="staff-active-orders-heading">
        <header class="flex items-center justify-between gap-3 border-b border-[var(--ps-border)] px-5 py-4 sm:px-6">
            <h2 id="staff-active-orders-heading" class="text-lg font-semibold text-[var(--ps-text)]">Orders to work on</h2>
            <a href="{{ route('staff.orders') }}" wire:navigate class="text-sm font-medium text-[var(--ps-accent)]">View all <span aria-hidden="true">→</span></a>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($activeOrders as $order)
                <li wire:key="staff-active-order-{{ $order->order_id }}">
                    <a href="{{ route('staff.orders.show', $order) }}" wire:navigate class="grid gap-2 px-5 py-4 transition-colors hover:bg-[var(--ps-accent-soft)]/40 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <div class="grid gap-1">
                            <h3 class="font-medium text-[var(--ps-text)]">Order #{{ $order->order_id }} <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $order->order_type }}</span></h3>
                            <p class="text-sm text-[var(--ps-muted)]">{{ $order->customer->name }} · {{ $order->order_date->format('M j, Y') }}</p>
                        </div>
                        <span class="flex flex-wrap items-center gap-2">
                            @if (! app(\App\Services\PaymentService::class)->isReadyForProduction($order))
                                <span class="ps-status-badge w-fit text-amber-500">Awaiting payment</span>
                            @endif
                            <span class="ps-status-badge w-fit">{{ OrderStatus::labelFor($order->status) }}</span>
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No orders to work on right now.</li>
            @endforelse
        </ul>
    </section>
</div>