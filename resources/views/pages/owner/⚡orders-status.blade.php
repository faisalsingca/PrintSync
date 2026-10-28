<?php

use App\Models\Order;
use App\OrderStatus;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Orders by Status')] class extends Component
{
    // Which status card is open. Empty means "all orders".
    #[Url(as: 'status')]
    public string $status = '';

    public string $search = '';

    /** @var array<string, int> */
    public array $counts = [];

    public function mount(OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $this->counts = collect($orderService->getOrderStatusCounts())
            ->pluck('count', 'status')
            ->all();

        if ($this->status !== '' && OrderStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }
    }

    public function selectStatus(string $status): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        // Pressing the open card again closes it.
        $this->status = ($status === $this->status || OrderStatus::tryFrom($status) === null) ? '' : $status;
    }

    /**
     * @return Collection<int, Order>
     */
    #[Computed]
    public function orders(): Collection
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $search = trim($this->search);

        return Order::query()
            ->with('customer')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                $query->where(function ($inner) use ($search, $like): void {
                    $inner->where('order_type', 'like', $like)
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $like));

                    if (ctype_digit(ltrim($search, '#'))) {
                        $inner->orWhere('order_id', (int) ltrim($search, '#'));
                    }
                });
            })
            ->latest('order_date')
            ->latest('order_id')
            ->limit(100)
            ->get();
    }
};
?>

<div class="owner-order-status-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Orders by Status</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">Select a status to see its orders and their details. Select it again to close.</p>
    </header>

    <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Order counts by status">
        @foreach (OrderStatus::cases() as $orderStatus)
            @php $selected = $status === $orderStatus->value; @endphp
            <button
                type="button"
                wire:key="owner-status-card-{{ $orderStatus->value }}"
                wire:click="selectStatus('{{ $orderStatus->value }}')"
                aria-pressed="{{ $selected ? 'true' : 'false' }}"
                @class([
                    'ps-glass flex cursor-pointer items-center justify-between gap-3 p-5 text-start transition-colors hover:border-[var(--ps-border-strong)]',
                    'ring-2 ring-[var(--ps-brand)]' => $selected,
                ])
            >
                <dt><x-status-badge :tone="$orderStatus->tone()" :label="$orderStatus->label()" /></dt>
                <dd class="text-3xl font-semibold tabular-nums text-[var(--ps-text)]">{{ number_format($counts[$orderStatus->value] ?? 0) }}</dd>
            </button>
        @endforeach
    </dl>

    <section class="ps-glass overflow-hidden" aria-labelledby="owner-status-orders-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <h2 id="owner-status-orders-heading" class="text-lg font-semibold text-[var(--ps-text)]">
                {{ $status === '' ? 'All orders' : OrderStatus::labelFor($status).' orders' }}
                <span class="font-normal text-[var(--ps-muted)]">({{ $this->orders->count() }})</span>
            </h2>
            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-xs" placeholder="Search order #, type, or customer" aria-label="Search orders">
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($this->orders as $order)
                <li wire:key="owner-status-order-{{ $order->order_id }}" class="grid gap-3 px-5 py-4 sm:px-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto] lg:items-center">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            Order #{{ $order->order_id }}
                            <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $order->order_type }}</span>
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">{{ $order->customerDisplayName() }}</p>
                        @if ($order->description)
                            <p class="line-clamp-2 text-sm text-[var(--ps-muted)]">{{ $order->description }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-[var(--ps-muted)]">Submitted</p>
                        <p class="text-sm font-medium text-[var(--ps-text)]">{{ $order->order_date->format('M j, Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-[var(--ps-muted)]">Quantity / total</p>
                        <p class="text-sm font-medium tabular-nums text-[var(--ps-text)]">
                            {{ number_format($order->quantity) }} {{ str('piece')->plural($order->quantity) }}
                            @if ($order->confirmed_total !== null)
                                <span class="text-[var(--ps-muted)]">·</span> ₱{{ number_format((float) $order->confirmed_total, 2) }}
                            @endif
                        </p>
                    </div>
                    <x-status-badge :tone="OrderStatus::toneFor($order->status)" :label="OrderStatus::labelFor($order->status)" />
                    <a href="{{ route('owner.orders.show', $order) }}" wire:navigate class="ps-button-secondary w-fit">View details</a>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No orders match.</li>
            @endforelse
        </ul>

        @if ($this->orders->count() === 100)
            <p class="border-t border-[var(--ps-border)] px-5 py-3 text-xs text-[var(--ps-muted)] sm:px-6">Showing the 100 most recent. Use search to narrow it down.</p>
        @endif
    </section>
</div>