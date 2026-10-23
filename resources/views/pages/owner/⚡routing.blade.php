<?php

use App\Models\Order;
use App\OrderServiceLine;
use App\OrderStatus;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Routing')] class extends Component
{
    public Collection $routingOverview;

    public int $unassignedCount = 0;

    public function mount(OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $this->routingOverview = $orderService->getRoutingOverview();
        $this->unassignedCount = $orderService->countUnassignedOrders();
    }
};
?>

<div class="owner-routing-page grid gap-8">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Order Routing</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Every order goes to all staff who handle that service. They all see it and any of them can work on it. This view is read-only.</p>
        </div>
        @if ($unassignedCount > 0)
            <x-status-badge tone="warning" :label="$unassignedCount.' '.str('order')->plural($unassignedCount).' waiting for staff'" />
        @endif
    </header>

    <section class="ps-glass overflow-hidden" aria-label="Order routing">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($routingOverview as $order)
                @php
                    $line = $order->service_line === null ? null : OrderServiceLine::tryFrom($order->service_line);
                    $team = $line === null ? collect() : app(OrderService::class)->getEligibleStaff($line, $order);
                @endphp
                <li wire:key="owner-routing-order-{{ $order->order_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,14rem)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            <a href="{{ route('owner.orders.show', $order) }}" wire:navigate class="hover:text-[var(--ps-accent)]">
                                Order #{{ $order->order_id }} <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $order->order_type }}</span>
                            </a>
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">{{ $order->customer->name }} · Submitted {{ $order->order_date->format('M j, Y') }}</p>
                    </div>
                    <p class="text-sm">
                        @if ($line === null)
                            <x-status-badge tone="neutral" label="No order type set" />
                        @elseif ($team->isEmpty())
                            <x-status-badge tone="warning" label="Waiting for eligible staff" />
                        @else
                            <span class="text-[var(--ps-muted)]">Shared by</span>
                            <span class="font-medium text-[var(--ps-text)]">{{ $team->pluck('name')->implode(', ') }}</span>
                        @endif
                    </p>
                    <x-status-badge :tone="OrderStatus::toneFor($order->status)" :label="OrderStatus::labelFor($order->status)" />
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No orders have been recorded.</li>
            @endforelse
        </ul>
    </section>
</div>