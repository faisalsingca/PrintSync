<?php

use App\Models\Order;
use App\OrderStatus;
use App\Services\OrderService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Details')] class extends Component
{
    public Order $order;

    public string $statusMessage = '';

    public function mount(Order $order): void
    {
        Gate::authorize('view', $order);

        $this->order = $order->load('customer');
    }

    public function updateProductionStatus(string $status, OrderService $orderService): void
    {
        $order = Order::query()->findOrFail($this->order->order_id);
        Gate::authorize('updateProductionStatus', $order);

        Validator::make(
            ['status' => $status],
            ['status' => ['required', Rule::enum(OrderStatus::class)]],
        )->validate();

        $this->order = $orderService->updateAssignedOrderStatus(
            $order,
            auth()->user(),
            OrderStatus::from($status),
        )->load('customer');
        $this->statusMessage = 'Order status updated to '.OrderStatus::from($status)->label().'.';
    }
};
?>

<div class="staff-order-details-page grid gap-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="grid gap-1">
            <p class="text-sm font-medium text-[var(--ps-muted)]">Order #{{ $order->order_id }}</p>
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Order Details</h1>
        </div>

        @if (auth()->user()->role === 'owner')
            <a href="{{ route('owner.monitoring') }}" wire:navigate class="ps-button-ghost w-fit">Back to monitoring</a>
        @else
            <a href="{{ route('staff.orders') }}" wire:navigate class="ps-button-ghost w-fit">Back to assigned orders</a>
        @endif
    </header>

    @if ($statusMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $statusMessage }}
        </div>
    @endif

    <section class="ps-glass grid gap-6 p-5 sm:p-8" aria-labelledby="staff-order-heading">
        <div class="flex flex-col gap-3 border-b border-[var(--ps-border)] pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid gap-1">
                <h2 id="staff-order-heading" class="text-xl font-semibold capitalize text-[var(--ps-text)]">{{ $order->order_type }}</h2>
                <p class="text-sm text-[var(--ps-muted)]">Customer: {{ $order->customer->name }}</p>
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

        @can('updateProductionStatus', $order)
            <div class="grid gap-3 border-t border-[var(--ps-border)] pt-5">
                <div class="grid gap-1">
                    <h2 class="font-semibold text-[var(--ps-text)]">Production Status</h2>
                    <p class="text-sm text-[var(--ps-muted)]">Advance the order through its production workflow.</p>
                </div>
                @if (($nextStatus = OrderStatus::tryFrom($order->status)?->next()) !== null)
                    <button
                        type="button"
                        wire:click="updateProductionStatus('{{ $nextStatus->value }}')"
                        wire:loading.attr="disabled"
                        class="ps-button-primary w-fit"
                    >
                        Mark {{ $nextStatus->label() }}
                    </button>
                @else
                    <p class="text-sm text-[var(--ps-muted)]">This order has completed production.</p>
                @endif
                <flux:error name="status" />
            </div>
        @endcan
    </section>
</div>