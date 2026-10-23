<?php

use App\Models\Order;
use App\OrderStatus;
use App\Services\OrderService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Orders by Status')] class extends Component
{
    public array $orderStatusCounts = [];

    public function mount(OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $this->orderStatusCounts = $orderService->getOrderStatusCounts();
    }
};
?>

<div class="owner-order-status-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Orders by Status</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">How many orders are in each stage right now.</p>
    </header>

    @if ($orderStatusCounts === [])
        <div class="ps-glass px-5 py-6 text-sm text-[var(--ps-muted)]">No orders have been recorded.</div>
    @else
        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($orderStatusCounts as $statusCount)
                <div wire:key="owner-status-{{ $statusCount['status'] }}" class="ps-glass flex items-center justify-between gap-3 p-5">
                    <dt><x-status-badge :tone="OrderStatus::toneFor($statusCount['status'])" :label="OrderStatus::labelFor($statusCount['status'])" /></dt>
                    <dd class="text-2xl font-semibold tabular-nums text-[var(--ps-text)]">{{ number_format($statusCount['count']) }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>