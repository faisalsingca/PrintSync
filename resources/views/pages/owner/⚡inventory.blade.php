<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inventory Materials')] class extends Component
{
    public Collection $inventory;

    public int $archivedCount = 0;

    public function mount(InventoryService $inventoryService): void
    {
        Gate::authorize('viewAny', Inventory::class);

        $this->inventory = $inventoryService->getInventoryList();
        $this->archivedCount = $inventoryService->getInventorySummary()['archived_count'];
    }
};
?>

@php
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="owner-inventory-page grid gap-8">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Inventory Materials</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Current quantities and status. The cashier manages the stock; every change is in the Audit Log.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($archivedCount > 0)
                <x-status-badge tone="neutral" :label="$archivedCount.' archived'" />
            @endif
            <a href="{{ route('owner.audit-logs') }}" wire:navigate class="ps-button-secondary">View audit log</a>
        </div>
    </header>

    <section class="ps-glass overflow-hidden" aria-label="Inventory materials">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                <li wire:key="owner-inventory-{{ $item->inventory_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center sm:gap-6 sm:px-6">
                    <div class="grid gap-0.5">
                        <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                        <p class="text-xs text-[var(--ps-muted)]">Low-stock level: {{ $fmt($item->low_stock_level) }} {{ $item->unit }}</p>
                    </div>
                    <p class="text-sm font-semibold tabular-nums text-[var(--ps-text)]">{{ $fmt($item->quantity) }} {{ $item->unit }}</p>
                    <x-status-badge :tone="$item->stockStatusTone()" :label="$item->stockStatusLabel()" />
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No inventory materials have been recorded.</li>
            @endforelse
        </ul>
    </section>
</div>