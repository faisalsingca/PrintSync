<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Archived Materials')] class extends Component
{
    public Collection $archived;

    public string $successMessage = '';

    public function mount(): void
    {
        Gate::authorize('manage', Inventory::class);

        $this->reload();
    }

    public function restore(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

        $item = Inventory::query()->archived()->findOrFail($inventoryId);

        $inventoryService->restoreMaterial($item, auth()->user());

        $this->successMessage = "{$item->material_name} is back in the inventory list.";
        $this->reload();
    }

    private function reload(): void
    {
        $this->archived = app(InventoryService::class)->getArchivedInventoryList();
    }
};
?>

@php
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="cashier-inventory-archive-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Archived Materials</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Archived materials keep their history. Restore one to use it again.</p>
        </div>
        <a href="{{ route('cashier.inventory') }}" wire:navigate class="ps-button-ghost w-fit">Back to inventory</a>
    </header>

    @if ($successMessage !== '')
        <div role="status" class="flex items-start gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            <span aria-hidden="true" class="font-bold text-emerald-500">✓</span> {{ $successMessage }}
        </div>
    @endif

    <section class="ps-glass overflow-hidden" aria-label="Archived materials">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($archived as $item)
                <li wire:key="archived-item-{{ $item->inventory_id }}" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-center sm:gap-6 sm:px-6">
                    <div class="grid gap-0.5">
                        <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                        <p class="text-xs text-[var(--ps-muted)]">Archived {{ $item->archived_at->format('M j, Y g:i A') }}</p>
                    </div>
                    <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $fmt($item->quantity) }} <span class="text-sm font-normal text-[var(--ps-muted)]">{{ $item->unit }}</span></p>
                    <x-status-badge tone="neutral" label="Archived" />
                    <button type="button" wire:click="restore({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary">Restore</button>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">Nothing is archived.</li>
            @endforelse
        </ul>
    </section>
</div>