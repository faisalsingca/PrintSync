<?php

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Details')] class extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        Gate::authorize('view', $order);

        $this->order = $order;
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
            <span class="ps-status-badge w-fit">{{ OrderStatus::labelFor($order->status) }}</span>
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
</div>