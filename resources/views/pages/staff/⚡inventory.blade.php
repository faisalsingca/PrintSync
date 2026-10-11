<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff Inventory')] class extends Component
{
    public Collection $inventory;

    public string $search = '';

    public bool $canManage = false;

    // Add-material form
    public string $material_name = '';

    public string $quantity = '';

    public string $unit = '';

    public string $status = 'available';

    // Edit-material form
    public ?int $editingId = null;

    public string $edit_material_name = '';

    public string $edit_quantity = '';

    public string $edit_unit = '';

    public string $edit_status = 'available';

    // Per-row inputs
    public array $stockAdd = [];

    public array $alertRemarks = [];

    public string $successMessage = '';

    public string $deleteError = '';

    public function mount(InventoryService $inventoryService): void
    {
        $this->canManage = Gate::allows('manage', Inventory::class);
        $this->inventory = $inventoryService->getInventoryList();
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
            'status' => ['required', Rule::in($this->statuses())],
        ]);

        $inventoryService->createMaterial($validated);

        $this->successMessage = "{$validated['material_name']} was added to inventory.";
        $this->deleteError = '';
        $this->reset('material_name', 'quantity', 'unit');
        $this->status = 'available';
        $this->reload();
    }

    public function startEdit(int $inventoryId): void
    {
        Gate::authorize('manage', Inventory::class);

        $item = Inventory::query()->findOrFail($inventoryId);

        $this->editingId = $item->inventory_id;
        $this->edit_material_name = $item->material_name;
        $this->edit_quantity = (string) $item->quantity;
        $this->edit_unit = $item->unit;
        $this->edit_status = $item->status;
        $this->resetErrorBag();
        $this->deleteError = '';
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function saveEdit(InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

        $item = Inventory::query()->findOrFail((int) $this->editingId);

        $validated = $this->validate([
            'edit_material_name' => ['required', 'string', 'max:150', Rule::unique('inventory', 'material_name')->ignore($item->inventory_id, 'inventory_id')],
            'edit_quantity' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'edit_unit' => ['required', 'string', 'max:30'],
            'edit_status' => ['required', Rule::in($this->statuses())],
        ]);

        $inventoryService->updateMaterial($item, [
            'material_name' => $validated['edit_material_name'],
            'quantity' => $validated['edit_quantity'],
            'unit' => $validated['edit_unit'],
            'status' => $validated['edit_status'],
        ]);

        $this->successMessage = "{$validated['edit_material_name']} was updated.";
        $this->editingId = null;
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

    public function addStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

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

    public function reportLowStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);

        $this->validate([
            "alertRemarks.$inventoryId" => ['nullable', 'string', 'max:500'],
        ]);

        $item = Inventory::query()->findOrFail($inventoryId);
        $remarks = trim((string) ($this->alertRemarks[$inventoryId] ?? ''));

        $inventoryService->createLowStockAlert($item, auth()->user(), $remarks === '' ? null : $remarks);

        $this->successMessage = "Low-stock alert recorded for {$item->material_name}.";
        $this->deleteError = '';
        unset($this->alertRemarks[$inventoryId]);
        $this->reload();
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return ['available', 'reserved', 'unavailable'];
    }

    private function reload(): void
    {
        $search = mb_strtolower(trim($this->search));

        $this->inventory = app(InventoryService::class)
            ->getInventoryList()
            ->when($search !== '', fn ($items) => $items
                ->filter(fn (Inventory $item): bool => str_contains(mb_strtolower($item->material_name), $search))
                ->values());
    }
};
?>

<div class="staff-inventory-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Inventory</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">
            @if ($canManage)
                Add, update, and delete materials, record incoming stock, and report low stock.
            @else
                Current materials, quantities, and status.
            @endif
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

    @if ($canManage)
        <section class="ps-glass grid gap-5 p-5 sm:p-6" aria-labelledby="add-material-heading">
            <h2 id="add-material-heading" class="text-lg font-semibold text-[var(--ps-text)]">Add material</h2>
            <form wire:submit="createMaterial" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto] xl:items-start">
                <div class="grid gap-1">
                    <label for="material_name" class="text-sm font-medium text-[var(--ps-text)]">Material name</label>
                    <input id="material_name" type="text" wire:model="material_name" class="ps-input" maxlength="150" required>
                    @error('material_name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-1">
                    <label for="quantity" class="text-sm font-medium text-[var(--ps-text)]">Quantity</label>
                    <input id="quantity" type="number" min="0" step="0.001" wire:model="quantity" class="ps-input" required>
                    @error('quantity') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-1">
                    <label for="unit" class="text-sm font-medium text-[var(--ps-text)]">Unit</label>
                    <input id="unit" type="text" wire:model="unit" class="ps-input" maxlength="30" placeholder="sheet, ream, roll" required>
                    @error('unit') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-1">
                    <label for="status" class="text-sm font-medium text-[var(--ps-text)]">Status</label>
                    <select id="status" wire:model="status" class="ps-input">
                        <option value="available">Available</option>
                        <option value="reserved">Reserved</option>
                        <option value="unavailable">Unavailable</option>
                    </select>
                    @error('status') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2 xl:col-span-1 xl:pt-6">
                    <button type="submit" class="ps-button-primary w-full" wire:loading.attr="disabled" wire:target="createMaterial">Add</button>
                </div>
            </form>
        </section>
    @endif

    <section class="ps-glass overflow-hidden" aria-labelledby="inventory-list-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <h2 id="inventory-list-heading" class="text-lg font-semibold text-[var(--ps-text)]">Materials</h2>
            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-xs" placeholder="Search materials" aria-label="Search materials">
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                <li wire:key="inventory-item-{{ $item->inventory_id }}" class="grid gap-4 px-5 py-4 sm:px-6">
                    @if ($canManage && $editingId === $item->inventory_id)
                        <form wire:submit="saveEdit" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto] xl:items-start">
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_material_name">Material name</label>
                                <input id="edit_material_name" type="text" wire:model="edit_material_name" class="ps-input" maxlength="150" required>
                                @error('edit_material_name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_quantity">Quantity</label>
                                <input id="edit_quantity" type="number" min="0" step="0.001" wire:model="edit_quantity" class="ps-input" required>
                                @error('edit_quantity') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_unit">Unit</label>
                                <input id="edit_unit" type="text" wire:model="edit_unit" class="ps-input" maxlength="30" required>
                                @error('edit_unit') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_status">Status</label>
                                <select id="edit_status" wire:model="edit_status" class="ps-input">
                                    <option value="available">Available</option>
                                    <option value="reserved">Reserved</option>
                                    <option value="unavailable">Unavailable</option>
                                    @if (! in_array($edit_status, ['available', 'reserved', 'unavailable'], true))
                                        <option value="{{ $edit_status }}">{{ str($edit_status)->replace('_', ' ')->title() }}</option>
                                    @endif
                                </select>
                                @error('edit_status') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex gap-2 xl:pt-6">
                                <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="saveEdit">Save</button>
                                <button type="button" wire:click="cancelEdit" class="ps-button-ghost">Cancel</button>
                            </div>
                        </form>
                    @else
                        <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center">
                            <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                            <p class="text-sm tabular-nums text-[var(--ps-text)]">{{ $item->quantity }} {{ $item->unit }}</p>
                            <span class="ps-status-badge w-fit">{{ str($item->status)->replace('_', ' ')->title() }}</span>
                        </div>

                        @if ($canManage)
                            <div class="grid gap-3 border-t border-[var(--ps-border)] pt-4 lg:grid-cols-[1fr_1fr_auto] lg:items-start">
                                <div class="grid gap-1">
                                    <div class="flex gap-2">
                                        <input type="number" min="0" step="0.001" wire:model="stockAdd.{{ $item->inventory_id }}" class="ps-input" placeholder="Quantity to add" aria-label="Quantity to add to {{ $item->material_name }}">
                                        <button type="button" wire:click="addStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary shrink-0">Add stock</button>
                                    </div>
                                    @error("stockAdd.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <div class="grid gap-1">
                                    <div class="flex gap-2">
                                        <input type="text" wire:model="alertRemarks.{{ $item->inventory_id }}" class="ps-input" placeholder="Remarks (optional)" aria-label="Low-stock remarks for {{ $item->material_name }}">
                                        <button type="button" wire:click="reportLowStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary shrink-0">Low stock</button>
                                    </div>
                                    @error("alertRemarks.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="startEdit({{ $item->inventory_id }})" class="ps-button-ghost">Edit</button>
                                    <button
                                        type="button"
                                        wire:click="deleteMaterial({{ $item->inventory_id }})"
                                        wire:confirm="Delete {{ $item->material_name }}? This cannot be undone."
                                        class="ps-button-ghost text-red-500"
                                    >Delete</button>
                                </div>
                            </div>
                        @endif
                    @endif
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">
                    {{ $search !== '' ? 'No materials match your search.' : 'No inventory items.' }}
                </li>
            @endforelse
        </ul>
    </section>
</div>
