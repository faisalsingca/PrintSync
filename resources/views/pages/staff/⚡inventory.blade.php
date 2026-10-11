<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Report Low Stock')] class extends Component
{
    public Collection $inventory;

    /** @var array<int, int> Inventory ids this staff member already reported and the cashier has not handled yet. */
    public array $reportedIds = [];

    public string $search = '';

    public array $alertRemarks = [];

    public string $successMessage = '';

    public function mount(InventoryService $inventoryService): void
    {
        Gate::authorize('viewAny', Inventory::class);

        $this->reload();
    }

    public function updatedSearch(): void
    {
        $this->reload();
    }

    /**
     * Tell the cashier a material is running low. The cashier handles the restock.
     */
    public function reportLowStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('createLowStockAlert', Inventory::class);

        $this->validate([
            "alertRemarks.$inventoryId" => ['nullable', 'string', 'max:500'],
        ]);

        $item = Inventory::query()->findOrFail($inventoryId);
        $staff = auth()->user();

        if ($inventoryService->hasOpenAlertFrom($item, $staff)) {
            $this->successMessage = "You already reported {$item->material_name}. The cashier has it and will restock it.";
            $this->reload();

            return;
        }

        $remarks = trim((string) ($this->alertRemarks[$inventoryId] ?? ''));

        $inventoryService->createLowStockAlert($item, $staff, $remarks === '' ? null : $remarks);

        $this->successMessage = "The cashier was told that {$item->material_name} is low.";
        unset($this->alertRemarks[$inventoryId]);
        $this->reload();
    }

    private function reload(): void
    {
        $service = app(InventoryService::class);
        $search = mb_strtolower(trim($this->search));

        $this->inventory = $service
            ->getInventoryList()
            ->when($search !== '', fn ($items) => $items
                ->filter(fn (Inventory $item): bool => str_contains(mb_strtolower($item->material_name), $search))
                ->values());

        $this->reportedIds = $service
            ->getOpenLowStockAlerts()
            ->where('staff_id', auth()->id())
            ->pluck('inventory_id')
            ->all();
    }
};
?>

@php
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="staff-inventory-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Report Low Stock</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">
            Running out of a material? Press <strong>Report low stock</strong> and the cashier will be told. The cashier handles all restocking.
        </p>
    </header>

    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-[var(--ps-muted)]" aria-label="Status guide">
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-500"></span> In Stock (Available)</span>
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-amber-500"></span> Low Stock (Requires Restock)</span>
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-red-500"></span> Out of Stock (No Stock)</span>
    </div>

    @if ($successMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $successMessage }}
        </div>
    @endif

    <section class="ps-glass overflow-hidden" aria-labelledby="inventory-list-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <h2 id="inventory-list-heading" class="text-lg font-semibold text-[var(--ps-text)]">Materials</h2>
            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-xs" placeholder="Search materials" aria-label="Search materials">
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                @php
                    $state = $item->stockStatus();
                    $alreadyReported = in_array($item->inventory_id, $reportedIds, true);
                @endphp
                <li wire:key="inventory-item-{{ $item->inventory_id }}" class="grid gap-4 px-5 py-4 sm:px-6">
                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center sm:gap-6">
                        <div class="grid gap-0.5">
                            <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                            <p class="text-xs text-[var(--ps-muted)]">Low-stock level: {{ $fmt($item->low_stock_level) }} {{ $item->unit }}</p>
                        </div>
                        <p class="text-lg font-semibold tabular-nums text-[var(--ps-text)]">{{ $fmt($item->quantity) }} <span class="text-sm font-normal text-[var(--ps-muted)]">{{ $item->unit }}</span></p>
                        <span @class([
                            'ps-status-badge inline-flex w-fit items-center gap-2',
                            'text-emerald-500' => $state === 'in_stock',
                            'text-amber-500' => $state === 'low_stock',
                            'text-red-500' => $state === 'out_of_stock',
                        ])>
                            <span @class([
                                'inline-block size-2.5 rounded-full',
                                'bg-emerald-500' => $state === 'in_stock',
                                'bg-amber-500' => $state === 'low_stock',
                                'bg-red-500' => $state === 'out_of_stock',
                            ])></span>
                            {{ $item->stockStatusLabel() }}
                        </span>
                    </div>

                    <div class="grid gap-1 border-t border-[var(--ps-border)] pt-4">
                        @if ($alreadyReported)
                            <p class="text-sm font-medium text-amber-500">Reported. Waiting for the cashier to restock.</p>
                        @else
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input type="text" wire:model="alertRemarks.{{ $item->inventory_id }}" class="ps-input" maxlength="500" placeholder="Remarks (optional)" aria-label="Low-stock remarks for {{ $item->material_name }}">
                                <button type="button" wire:click="reportLowStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary shrink-0">Report low stock</button>
                            </div>
                            @error("alertRemarks.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">
                    {{ $search !== '' ? 'No materials match your search.' : 'No inventory items.' }}
                </li>
            @endforelse
        </ul>
    </section>
</div>