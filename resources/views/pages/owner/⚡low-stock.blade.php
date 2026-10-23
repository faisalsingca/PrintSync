<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Low-Stock Alerts')] class extends Component
{
    public Collection $lowStockAlerts;

    public function mount(InventoryService $inventoryService): void
    {
        Gate::authorize('viewAny', Inventory::class);

        $this->lowStockAlerts = $inventoryService->getLowStockAlerts();
    }
};
?>

<div class="owner-low-stock-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Low-Stock Alerts</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">Reports sent by staff and alerts made when a deduction left a material low. Only recorded alerts are listed.</p>
    </header>

    <section class="ps-glass overflow-hidden" aria-label="Recorded low-stock alerts">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($lowStockAlerts as $alert)
                <li wire:key="owner-low-stock-alert-{{ $alert->alert_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="flex flex-wrap items-center gap-2 font-medium text-[var(--ps-text)]">
                            {{ $alert->inventory->material_name }}
                            <x-status-badge tone="warning" label="Low stock" />
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">
                            Current quantity: {{ $alert->inventory->quantity }} {{ $alert->inventory->unit }}
                            @if ($alert->remarks)
                                <span aria-hidden="true">·</span> {{ $alert->remarks }}
                            @endif
                        </p>
                    </div>
                    <time class="text-sm text-[var(--ps-muted)]" datetime="{{ $alert->alert_date->toIso8601String() }}">{{ $alert->alert_date->format('M j, Y g:i A') }}</time>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No low-stock alerts have been recorded.</li>
            @endforelse
        </ul>
    </section>
</div>