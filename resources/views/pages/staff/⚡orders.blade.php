<?php

use App\Models\OrderAssignment;
use App\OrderStatus;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Staff Orders')] class extends Component
{
    public Collection $orders;

    public Collection $taskOffers;

    public string $responseMessage = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(OrderService $orderService): void
    {
        $this->refreshOrders($orderService);
    }

    #[Computed]
    public function filteredOrders(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $this->orders
            ->when($this->statusFilter !== '', fn ($orders) => $orders->where('status', $this->statusFilter))
            ->when($search !== '', fn ($orders) => $orders->filter(
                fn ($order): bool => str_contains(
                    mb_strtolower($order->order_type.' '.$order->customer->name.' #'.$order->order_id),
                    $search,
                )
            ))
            ->values();
    }

    public function acceptTask(int $assignmentId, OrderService $orderService): void
    {
        $this->respondToTask($assignmentId, true, $orderService);
    }

    public function declineTask(int $assignmentId, OrderService $orderService): void
    {
        $this->respondToTask($assignmentId, false, $orderService);
    }

    private function respondToTask(int $assignmentId, bool $accept, OrderService $orderService): void
    {
        $assignment = OrderAssignment::query()
            ->with('order')
            ->findOrFail($assignmentId);

        Gate::authorize('respondToAssignment', [$assignment->order, $assignment]);

        $orderService->respondToAssignment($assignment, auth()->user(), $accept);
        $this->refreshOrders($orderService);
        $this->responseMessage = $accept
            ? "Order #{$assignment->order_id} was accepted."
            : "Order #{$assignment->order_id} was declined and returned for routing.";
    }

    private function refreshOrders(OrderService $orderService): void
    {
        $staff = auth()->user();
        $this->orders = $orderService->getStaffOrders($staff);
        $this->taskOffers = $orderService->getStaffTaskOffers($staff);
    }
};
?>

<div class="staff-orders-page grid gap-6">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Assigned Orders</h1>
        <p class="text-sm text-[var(--ps-muted)]">Orders assigned to you automatically, based on your service specialty.</p>
    </header>

    @if ($responseMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $responseMessage }}
        </div>
    @endif

    {{-- Only shown if an older task offer is still waiting for an answer --}}
    @if ($taskOffers->isNotEmpty())
        <section class="ps-glass grid gap-5 p-5 sm:p-6" aria-labelledby="staff-task-offers-heading">
            <div class="grid gap-1">
                <h2 id="staff-task-offers-heading" class="text-xl font-semibold text-[var(--ps-text)]">Task Offers</h2>
                <p class="text-sm text-[var(--ps-muted)]">Accept to add an order to your assigned list, or decline to return it for reassignment.</p>
            </div>
            <ul class="grid gap-4">
                @foreach ($taskOffers as $offer)
                    <li wire:key="staff-task-offer-{{ $offer->assignment_id }}" class="grid gap-4 rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-5">
                        <a href="{{ route('staff.orders.show', $offer->order) }}" wire:navigate class="grid gap-1">
                            <h3 class="font-semibold text-[var(--ps-text)]">Order #{{ $offer->order->order_id }} <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $offer->order->order_type }}</span></h3>
                            <p class="text-sm text-[var(--ps-muted)]">{{ $offer->order->customer->name }} · {{ number_format($offer->order->quantity) }} {{ str('copy')->plural($offer->order->quantity) }} · {{ $offer->order->order_date->format('M j, Y') }}</p>
                        </a>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button type="button" wire:click="acceptTask({{ $offer->assignment_id }})" wire:loading.attr="disabled" class="ps-button-primary">Accept</button>
                            <button type="button" wire:click="declineTask({{ $offer->assignment_id }})" wire:loading.attr="disabled" class="ps-button-secondary">Decline</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="grid gap-4" aria-label="Filter assigned orders">
        <div class="ps-settings-tabs">
            <button type="button" wire:click="$set('statusFilter', '')" class="ps-settings-tab cursor-pointer" @if ($statusFilter === '') data-current @endif>
                All <span class="tabular-nums">({{ $orders->count() }})</span>
            </button>
            @foreach (OrderStatus::cases() as $status)
                <button
                    type="button"
                    wire:key="staff-filter-{{ $status->value }}"
                    wire:click="$set('statusFilter', '{{ $status->value }}')"
                    class="ps-settings-tab cursor-pointer"
                    @if ($statusFilter === $status->value) data-current @endif
                >
                    {{ $status->label() }} <span class="tabular-nums">({{ $orders->where('status', $status->value)->count() }})</span>
                </button>
            @endforeach
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-sm" placeholder="Search by order #, type, or customer" aria-label="Search assigned orders">
    </section>

    <section class="ps-glass overflow-hidden">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($this->filteredOrders as $order)
                <li wire:key="staff-order-{{ $order->order_id }}">
                    <a
                        href="{{ route('staff.orders.show', $order) }}"
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
                                <p class="flex flex-wrap gap-x-2 gap-y-1 text-sm text-[var(--ps-muted)]">
                                    <span>{{ number_format($order->quantity) }} {{ str('copy')->plural($order->quantity) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $order->order_date->format('M j, Y') }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $order->customer->name }}</span>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                            <span class="flex flex-wrap items-center gap-2">
                                @if (! app(\App\Services\PaymentService::class)->isReadyForProduction($order))
                                    <span class="ps-status-badge text-amber-500">Awaiting payment</span>
                                @endif
                                <span class="ps-status-badge">{{ OrderStatus::labelFor($order->status) }}</span>
                            </span>
                            <span class="text-sm font-medium text-[var(--ps-accent)]">Details <span aria-hidden="true">→</span></span>
                        </div>
                    </a>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">
                    {{ $statusFilter !== '' || $search !== '' ? 'No assigned orders match your filter.' : 'No assigned orders.' }}
                </li>
            @endforelse
        </ul>
    </section>
</div>