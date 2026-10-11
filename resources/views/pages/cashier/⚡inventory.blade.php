<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cashier Inventory')] class extends Component
{
    public Collection $inventory;

    public Collection $openAlerts;

    public string $search = '';

    // Add-material form
    public string $material_name = '';

    public string $quantity = '';

    public string $unit = '';

    public string $low_stock_level = '10';

    // Per-row inputs
    public array $stockAdd = [];

    public array $deduct = [];

    public string $successMessage = '';

    public string $deleteError = '';

    public function mount(): void
    {
        Gate::authorize('manage', Inventory::class);

        $this->reload();
    }

    public function updatedSearch(): void
    {
        $this->reload();
    }

    public function createMaterial(InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

        $validated = $this->validate([
            'material_name' => ['required', 'string', 'max:150', Rule::unique('inventory', 'material_name')],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'unit' => ['required', 'string', 'max:30'],
            'low_stock_level' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
        ]);

        $inventoryService->createMaterial($validated);

        $this->successMessage = "{$validated['material_name']} was added to inventory.";
        $this->deleteError = '';
        $this->reset('material_name', 'quantity', 'unit');
        $this->low_stock_level = '10';
        $this->reload();
    }

    public function addStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('addStock', Inventory::class);

        $this->validate([
            "stockAdd.$inventoryId" => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
        ], [], ["stockAdd.$inventoryId" => 'quantity to add']);

        $item = Inventory::query()->findOrFail($inventoryId);
        $inventoryService->addStock($item, auth()->user(), (string) $this->stockAdd[$inventoryId]);

        $this->successMessage = "Stock added to {$item->material_name}.";
        $this->deleteError = '';
        unset($this->stockAdd[$inventoryId]);
        $this->reload();
    }

    public function deductStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

        $this->validate([
            "deduct.$inventoryId" => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
        ], [], ["deduct.$inventoryId" => 'quantity to deduct']);

        $item = Inventory::query()->findOrFail($inventoryId);

        try {
            $updated = $inventoryService->deductStock($item, auth()->user(), (string) $this->deduct[$inventoryId]);
        } catch (ValidationException $exception) {
            $this->addError("deduct.$inventoryId", $exception->errors()['deduct'][0] ?? 'This quantity cannot be deducted.');

            return;
        }

        $this->successMessage = "Deducted {$this->deduct[$inventoryId]} {$updated->unit} from {$updated->material_name}. Status: {$updated->stockStatusLabel()}.";
        $this->deleteError = '';
        unset($this->deduct[$inventoryId]);
        $this->reload();
    }

    public function deleteMaterial(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

        $item = Inventory::query()->findOrFail($inventoryId);

        try {
            $inventoryService->deleteMaterial($item);
        } catch (ValidationException $exception) {
            $this->deleteError = $exception->errors()['inventory'][0] ?? 'This material cannot be deleted.';

            return;
        }

        $this->deleteError = '';
        $this->successMessage = "{$item->material_name} was deleted.";
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

        $this->openAlerts = $service->getOpenLowStockAlerts();
    }
};
?>

@php
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="cashier-inventory-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Inventory</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">
            Add materials, record incoming stock, and deduct what was used. The status updates by itself. Low-stock reports from staff appear below.
        </p>
    </header>

    @if ($successMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $successMessage }}
        </div>
    @endif

    @if ($deleteError !== '')
        <div role="alert" class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $deleteError }}
        </div>
    @endif

    <section class="ps-glass overflow-hidden" aria-labelledby="low-stock-reports-heading">
        <header class="grid gap-1 border-b border-[var(--ps-border)] px-5 py-5 sm:px-6">
            <h2 id="low-stock-reports-heading" class="text-lg font-semibold text-[var(--ps-text)]">
                Low-stock reports from staff
                <span class="ps-status-badge ms-2 align-middle">{{ $openAlerts->count() }} open</span>
            </h2>
            <p class="text-sm text-[var(--ps-muted)]">A report is cleared automatically once you add stock to that material.</p>
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($openAlerts as $alert)
                <li wire:key="open-alert-{{ $alert->alert_id }}" class="grid gap-1 px-5 py-4 sm:px-6">
                    <h3 class="font-medium text-[var(--ps-text)]">{{ $alert->inventory->material_name }}</h3>
                    <p class="text-sm text-[var(--ps-muted)]">
                        Reported by {{ $alert->staff->name }}
                        @php
                            $specialties = $alert->staff->specialties
                                ->map(fn ($specialty) => \App\StaffSpecialtyType::tryFrom($specialty->specialty)?->label())
                                ->filter()
                                ->implode(', ');
                        @endphp
                        @if ($specialties !== '')
                            ({{ $specialties }})
                        @endif
                        <span class="px-1" aria-hidden="true">·</span>
                        {{ $alert->alert_date->format('M j, Y g:i A') }}
                    </p>
                    <p class="text-sm text-[var(--ps-muted)]">
                        Now: {{ $fmt($alert->inventory->quantity) }} {{ $alert->inventory->unit }}
                        <span class="px-1" aria-hidden="true">·</span>
                        {{ $alert->inventory->stockStatusLabel() }}
                    </p>
                    @if ($alert->remarks)
                        <p class="text-sm text-[var(--ps-text)]">“{{ $alert->remarks }}”</p>
                    @endif
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No open low-stock reports.</li>
            @endforelse
        </ul>
    </section>

    <section class="ps-glass grid gap-5 p-5 sm:p-6" aria-labelledby="add-material-heading">
        <h2 id="add-material-heading" class="text-lg font-semibold text-[var(--ps-text)]">Add material</h2>
        <form wire:submit="createMaterial" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto] xl:items-start">
            <div class="grid gap-1">
                <label for="material_name" class="text-sm font-medium text-[var(--ps-text)]">Material name</label>
                <input id="material_name" type="text" wire:model="material_name" class="ps-input" maxlength="150" required>
                @error('material_name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-1">
                <label for="quantity" class="text-sm font-medium text-[var(--ps-text)]">Starting quantity</label>
                <input id="quantity" type="number" min="0" step="0.001" wire:model="quantity" class="ps-input" required>
                @error('quantity') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-1">
                <label for="unit" class="text-sm font-medium text-[var(--ps-text)]">Unit</label>
                <input id="unit" type="text" wire:model="unit" class="ps-input" maxlength="30" placeholder="sheet, ream, roll" required>
                @error('unit') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-1">
                <label for="low_stock_level" class="text-sm font-medium text-[var(--ps-text)]">Low-stock level</label>
                <input id="low_stock_level" type="number" min="0" step="0.001" wire:model="low_stock_level" class="ps-input" required>
                @error('low_stock_level') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-2 xl:col-span-1 xl:pt-6">
                <button type="submit" class="ps-button-primary w-full" wire:loading.attr="disabled" wire:target="createMaterial">Add</button>
            </div>
        </form>
        <p class="text-xs leading-5 text-[var(--ps-muted)]">Low Stock shows when the quantity reaches the low-stock level or below. Out of Stock shows at 0.</p>
    </section>

    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-[var(--ps-muted)]" aria-label="Status guide">
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-500"></span> In Stock (Available)</span>
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-amber-500"></span> Low Stock (Requires Restock)</span>
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-red-500"></span> Out of Stock (No Stock)</span>
    </div>

    <section class="ps-glass overflow-hidden" aria-labelledby="inventory-list-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <h2 id="inventory-list-heading" class="text-lg font-semibold text-[var(--ps-text)]">Materials</h2>
            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-xs" placeholder="Search materials" aria-label="Search materials">
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                @php $state = $item->stockStatus(); @endphp
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

                    <div class="grid gap-3 border-t border-[var(--ps-border)] pt-4 lg:grid-cols-[1fr_1fr_auto] lg:items-start">
                        <div class="grid gap-1">
                            <div class="flex gap-2">
                                <input type="number" min="0" step="0.001" wire:model="stockAdd.{{ $item->inventory_id }}" class="ps-input" placeholder="How many to add" aria-label="Quantity to add to {{ $item->material_name }}">
                                <button type="button" wire:click="addStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary shrink-0">Add stock</button>
                            </div>
                            @error("stockAdd.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-1">
                            <div class="flex gap-2">
                                <input type="number" min="0" step="0.001" wire:model="deduct.{{ $item->inventory_id }}" class="ps-input" placeholder="How many to deduct" aria-label="Quantity to deduct from {{ $item->material_name }}">
                                <button type="button" wire:click="deductStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-primary shrink-0">Deduct</button>
                            </div>
                            @error("deduct.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <button
                                type="button"
                                wire:click="deleteMaterial({{ $item->inventory_id }})"
                                wire:confirm="Delete {{ $item->material_name }}? This cannot be undone."
                                class="ps-button-ghost text-red-500"
                            >Delete</button>
                        </div>
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