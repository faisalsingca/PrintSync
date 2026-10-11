<?php

use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer Dashboard')] class extends Component
{
    public Collection $recentOrders;

    public int $activeOrderCount = 0;

    public int $pendingOrderCount = 0;

    public int $completedOrderCount = 0;

    public function mount(OrderService $orderService): void
    {
        $orders = $orderService->getCustomerOrders(auth()->user());

        $this->activeOrderCount = $orders
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
        $this->pendingOrderCount = $orders->where('status', 'pending')->count();
        $this->completedOrderCount = $orders->where('status', 'completed')->count();
        $this->recentOrders = $orders
            ->sortByDesc('order_date')
            ->take(5)
            ->values();
    }
};
?>

<div class="customer-dashboard-page grid gap-8">
    <section class="relative isolate overflow-hidden rounded-[1.6rem] border border-[var(--ps-border-strong)] bg-[radial-gradient(ellipse_at_82%_8%,rgb(184_243_74/13%),transparent_42%),var(--ps-glass)] px-6 py-8 shadow-[var(--ps-shadow)] sm:px-9 sm:py-10">
        <div class="pointer-events-none absolute -right-16 -top-12 -z-10 h-64 w-64 rounded-full border border-[var(--ps-accent)]/10 bg-[var(--ps-accent)]/5 blur-3xl" aria-hidden="true"></div>
        <div class="flex flex-col gap-7 sm:flex-row sm:items-end sm:justify-between">
            <div class="grid gap-3">
                <span class="inline-flex w-fit items-center gap-2 rounded-full border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-3 py-1 text-xs font-semibold tracking-wide text-[var(--ps-accent)]">
                    <span class="size-1.5 rounded-full bg-[var(--ps-accent)] shadow-[0_0_10px_var(--ps-accent)]" aria-hidden="true"></span>
                    CUSTOMER WORKSPACE
                </span>
                <div class="grid gap-2">
                    <h1 class="text-3xl font-semibold tracking-[-0.045em] text-[var(--ps-text)] sm:text-4xl">
                        Welcome back, {{ auth()->user()->name }}
                    </h1>
                    <p class="max-w-xl text-sm leading-6 text-[var(--ps-muted)] sm:text-base">
                        Your print projects, moving from idea to finished.
                    </p>
                </div>
            </div>

            <a
                href="{{ route('customer.orders.create') }}"
                wire:navigate
                class="ps-button-primary w-full shrink-0 sm:w-auto"
            >
                <span class="grid size-5 place-items-center rounded-full bg-black/10 text-base leading-none" aria-hidden="true">+</span>
                Create New Order
                <span class="text-base leading-none" aria-hidden="true">↗</span>
            </a>
        </div>
    </section>

    <section aria-label="Order summary" class="grid gap-4 sm:grid-cols-3">
        <article class="ps-glass group grid gap-5 p-5 transition duration-200 hover:-translate-y-0.5 hover:border-[var(--ps-border-strong)] sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium text-[var(--ps-muted)]">Active Orders</h2>
                <span class="grid size-9 place-items-center rounded-xl bg-[var(--ps-accent-soft)] text-[var(--ps-accent)]" aria-hidden="true">↗</span>
            </div>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $activeOrderCount }}</p>
        </article>

        <article class="ps-glass group grid gap-5 p-5 transition duration-200 hover:-translate-y-0.5 hover:border-[var(--ps-border-strong)] sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium text-[var(--ps-muted)]">Pending Orders</h2>
                <span class="grid size-9 place-items-center rounded-xl border border-[var(--ps-border)] text-[var(--ps-muted)]" aria-hidden="true">◷</span>
            </div>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $pendingOrderCount }}</p>
        </article>

        <article class="ps-glass group grid gap-5 p-5 transition duration-200 hover:-translate-y-0.5 hover:border-[var(--ps-border-strong)] sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium text-[var(--ps-muted)]">Completed Orders</h2>
                <span class="grid size-9 place-items-center rounded-xl border border-[var(--ps-border)] text-[var(--ps-accent)]" aria-hidden="true">✓</span>
            </div>
            <p class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $completedOrderCount }}</p>
        </article>
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="recent-orders-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="grid gap-1">
                <h2 id="recent-orders-heading" class="text-lg font-semibold tracking-tight text-[var(--ps-text)]">Recent Orders</h2>
                <p class="text-sm text-[var(--ps-muted)]">A quick look at your latest print work.</p>
            </div>
            <a
                href="{{ route('customer.orders') }}"
                wire:navigate
                class="ps-button-ghost min-h-10 w-fit text-sm"
            >
                View all orders <span aria-hidden="true">→</span>
            </a>
        </header>

        @if ($recentOrders->isEmpty())
            <div class="grid justify-items-center gap-4 px-6 py-12 text-center sm:py-16">
                <span class="grid size-14 place-items-center rounded-2xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] text-2xl text-[var(--ps-accent)]" aria-hidden="true">＋</span>
                <div class="grid gap-1.5">
                    <h3 class="font-semibold text-[var(--ps-text)]">Your next great print starts here.</h3>
                    <p class="max-w-sm text-sm leading-6 text-[var(--ps-muted)]">You don't have any orders yet. Start a new order and we'll take it from there.</p>
                </div>
                <a href="{{ route('customer.orders.create') }}" wire:navigate class="ps-button-secondary">
                    Create your first order
                </a>
            </div>
        @else
            <div class="divide-y divide-[var(--ps-border)]">
                @foreach ($recentOrders as $order)
                    <article wire:key="customer-recent-order-{{ $order->order_id }}" class="group grid gap-4 px-5 py-5 transition-colors hover:bg-[var(--ps-accent-soft)]/40 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <div class="grid gap-3 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center sm:gap-4">
                            <span class="grid size-11 place-items-center rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] font-mono text-xs font-semibold text-[var(--ps-muted)]">
                                #{{ $order->order_id }}
                            </span>
                            <div class="grid gap-1.5">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <h3 class="font-semibold capitalize text-[var(--ps-text)]">{{ $order->order_type }}</h3>
                                    <span class="ps-status-badge">{{ str($order->status)->replace('_', ' ')->title() }}</span>
                                </div>
                                <p class="text-sm text-[var(--ps-muted)]">
                                    {{ number_format($order->quantity) }} {{ str('copy')->plural($order->quantity) }}
                                    <span class="px-1.5 text-[var(--ps-border-strong)]" aria-hidden="true">·</span>
                                    {{ $order->order_date->format('M j, Y') }}
                                </p>
                            </div>
                        </div>
                        <a
                            href="{{ route('customer.orders') }}"
                            wire:navigate
                            class="ps-button-ghost min-h-9 w-fit text-sm"
                            aria-label="View order #{{ $order->order_id }}"
                        >
                            Details <span aria-hidden="true">→</span>
                        </a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>