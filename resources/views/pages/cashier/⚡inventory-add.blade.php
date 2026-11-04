<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add Material')] class extends Component
{
    public string $material_name = '';

    public string $quantity = '';

    public string $unit = '';

    public string $low_stock_level = '10';

    public function mount(): void
    {
        Gate::authorize('manage', Inventory::class);
    }

    public function createMaterial(InventoryService $inventoryService): ?RedirectResponse
    {
        Gate::authorize('manage', Inventory::class);

        $validated = $this->validate([
            'material_name' => ['required', 'string', 'max:150', Rule::unique('inventory', 'material_name')],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'unit' => ['required', 'string', 'max:30'],
            'low_stock_level' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
        ], [
            'material_name.unique' => 'A material with this name already exists (it may be in Archived Materials).',
        ]);

        $inventoryService->createMaterial($validated, auth()->user());

        session()->flash('status', "{$validated['material_name']} was added to inventory.");

        return redirect()->route('cashier.inventory');
    }
};
?>

<div class="cashier-inventory-add-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Add Material</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Low Stock shows when the quantity reaches the low-stock level or below. Out of Stock shows at 0.</p>
        </div>
        <a href="{{ route('cashier.inventory') }}" wire:navigate class="ps-button-ghost w-fit">Back to inventory</a>
    </header>

    <form wire:submit="createMaterial" class="ps-glass grid gap-6 p-5 sm:p-8">
        <div class="grid gap-6 md:grid-cols-2">
            <div class="grid gap-1 md:col-span-2">
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
        </div>

        <div class="flex justify-end gap-3 border-t border-[var(--ps-border)] pt-5">
            <a href="{{ route('cashier.inventory') }}" wire:navigate class="ps-button-secondary">Cancel</a>
            <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="createMaterial">Add material</button>
        </div>
    </form>
</div>