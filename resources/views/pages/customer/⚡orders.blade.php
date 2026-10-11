<?php

use App\Services\OrderService;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer Orders')] class extends Component
{
    public Collection $orders;

    public function mount(OrderService $orderService): void
    {
        $this->orders = $orderService->getCustomerOrders(auth()->user());
    }
};
?>

<div>
    <div class="customer-orders-page grid gap-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid gap-1">
                <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Customer Orders</h1>
                <p class="text-sm text-[var(--ps-muted)]">Review your submitted print projects.</p>
            </div>

            <a href="{{ route('customer.orders.create') }}" wire:navigate class="ps-button-primary w-full sm:w-auto">
                Create New Order
            </a>
        </header>

        @if (session('status'))
            <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
                {{ session('status') }}
            </div>
        @endif

        <section class="ps-glass overflow-hidden">
            <ul class="divide-y divide-[var(--ps-border)]">
                @forelse ($orders as $order)
                    <li wire:key="customer-order-{{ $order->order_id }}">
                        <a
                            href="{{ route('customer.orders.show', $order) }}"
                            wire:navigate
                            class="grid gap-4 px-5 py-4 transition-colors hover:bg-[var(--ps-accent-soft)]/40 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6"
                            aria-label="View order #{{ $order->order_id }}"
                        >
                            <div class="grid gap-3 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center sm:gap-4">
                                <span class="grid size-11 place-items-center rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] font-mono text-xs font-semibold text-[var(--ps-muted)]">
                                    #{{ $order->order_id }}
                                </span>
                                <div class="grid gap-1.5">
                                    <h2 class="font-semibold capitalize text-[var(--ps-text)]">{{ $order->order_type }}</h2>
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-[var(--ps-muted)]">
                                        <span>{{ number_format($order->quantity) }} {{ str('copy')->plural($order->quantity) }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $order->order_date->format('M j, Y') }}</span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 sm:justify-end">
                                <span class="ps-status-badge">{{ OrderStatus::labelFor($order->status) }}</span>
                                <span class="text-sm font-medium text-[var(--ps-accent)]">Details <span aria-hidden="true">→</span></span>
                            </div>
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No orders yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>