<?php

use App\Models\Inventory;
use App\Models\InventoryAdjustmentRequest;
use App\Services\InventoryService;
use App\StaffSpecialtyType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cashier Inventory')] class extends Component
{
    public Collection $inventory;

    public string $search = '';

    // Per-row inputs
    public array $stockAdd = [];

    public array $deduct = [];

    public array $deductNote = [];

    public Collection $adjustmentRequests;

    // Inline edit
    public ?int $editingId = null;

    public string $edit_name = '';

    public string $edit_unit = '';

    public string $edit_low_stock_level = '';

    public array $edit_specialties = [];

    public bool $editAllSpecialties = true;

    public string $successMessage = '';

    public string $errorMessage = '';

    public bool $showArchiveConfirmationModal = false;

    public ?int $pendingArchiveMaterialId = null;

    public string $pendingArchiveMaterialName = '';

    public function mount(): void
    {
        Gate::authorize('manage', Inventory::class);

        $this->successMessage = (string) session('status', '');
        $this->reload();
    }

    public function updatedSearch(): void
    {
        $this->reload();
    }

    public function addStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('addStock', Inventory::class);
        $this->clearMessages();

        $this->validate([
            "stockAdd.$inventoryId" => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
        ], [], ["stockAdd.$inventoryId" => 'quantity to add']);

        $item = Inventory::query()->findOrFail($inventoryId);

        try {
            $inventoryService->addStock($item, auth()->user(), (string) $this->stockAdd[$inventoryId]);
        } catch (ValidationException $exception) {
            $this->errorMessage = $exception->errors()['inventory'][0] ?? 'Stock could not be added.';
            $this->reload();

            return;
        }

        $this->successMessage = "Stock added to {$item->material_name}.";
        unset($this->stockAdd[$inventoryId]);
        $this->reload();
    }

    public function deductStock(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);
        $this->clearMessages();

        $this->validate([
            "deduct.$inventoryId" => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            "deductNote.$inventoryId" => ['nullable', 'string', 'max:255'],
        ], [], ["deduct.$inventoryId" => 'quantity to deduct']);

        $item = Inventory::query()->findOrFail($inventoryId);

        try {
            $updated = $inventoryService->deductStock(
                $item,
                auth()->user(),
                (string) $this->deduct[$inventoryId],
                $this->deductNote[$inventoryId] ?? null,
            );
        } catch (ValidationException $exception) {
            $message = $exception->errors()['deduct'][0] ?? $exception->errors()['inventory'][0] ?? 'This quantity cannot be deducted.';
            $this->addError("deduct.$inventoryId", $message);

            return;
        }

        $this->successMessage = "Deducted {$this->deduct[$inventoryId]} {$updated->unit} from {$updated->material_name}.";
        unset($this->deduct[$inventoryId], $this->deductNote[$inventoryId]);
        $this->reload();
    }

    public function startEdit(int $inventoryId): void
    {
        Gate::authorize('manage', Inventory::class);

        $item = Inventory::query()->active()->findOrFail($inventoryId);

        $this->editingId = $item->inventory_id;
        $this->edit_name = $item->material_name;
        $this->edit_unit = $item->unit;
        $this->edit_low_stock_level = rtrim(rtrim(number_format((float) $item->low_stock_level, 3, '.', ''), '0'), '.');
        $specialties = $item->specialties()->pluck('specialty')->all();
        $this->editAllSpecialties = in_array('all', $specialties, true);
        $this->edit_specialties = $this->editAllSpecialties ? [] : $specialties;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetValidation();
    }

    public function saveEdit(InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);
        $this->clearMessages();

        $item = Inventory::query()->active()->findOrFail($this->editingId);

        $validated = $this->validate([
            'edit_name' => ['required', 'string', 'max:150', Rule::unique('inventory', 'material_name')->ignore($item->inventory_id, 'inventory_id')],
            'edit_unit' => ['required', 'string', 'max:30'],
            'edit_low_stock_level' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'edit_specialties' => ['array', Rule::when(! $this->editAllSpecialties, ['min:1'])],
            'edit_specialties.*' => ['string', 'distinct', Rule::enum(StaffSpecialtyType::class)],
        ], [
            'edit_name.unique' => 'Another material (maybe an archived one) already uses this name.',
            'edit_specialties.min' => 'Select at least one specialty or choose all specialties.',
        ]);

        $inventoryService->updateMaterial($item, [
            'material_name' => $validated['edit_name'],
            'unit' => $validated['edit_unit'],
            'low_stock_level' => (string) $validated['edit_low_stock_level'],
        ], auth()->user());
        $inventoryService->updateMaterialSpecialties(
            $item,
            $this->editAllSpecialties ? ['all'] : $validated['edit_specialties'],
            auth()->user(),
        );

        $this->successMessage = "{$validated['edit_name']} was updated.";
        $this->editingId = null;
        $this->edit_specialties = [];
        $this->editAllSpecialties = true;
        $this->reload();
    }

    public function startAdjustmentReview(int $requestId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);
        $this->clearMessages();

        $request = InventoryAdjustmentRequest::query()->findOrFail($requestId);
        $inventoryService->startAdjustmentRequest($request, auth()->user());

        $this->successMessage = "Usage report #{$request->request_id} is in progress.";
        $this->reload();
    }

    public function completeAdjustment(int $requestId, InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);
        $this->clearMessages();

        $request = InventoryAdjustmentRequest::query()->findOrFail($requestId);

        try {
            $inventoryService->completeAdjustmentRequest($request, auth()->user());
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->errorMessage = $errors['deduct'][0]
                ?? $errors['inventory'][0]
                ?? $errors['adjustment'][0]
                ?? 'The usage report could not be completed.';

            return;
        }

        $this->successMessage = "Usage report #{$request->request_id} was completed and stock was updated.";
        $this->reload();
    }

    public function requestArchiveMaterial(int $inventoryId): void
    {
        Gate::authorize('manage', Inventory::class);

        $item = Inventory::query()->active()->findOrFail($inventoryId);

        $this->pendingArchiveMaterialId = $item->inventory_id;
        $this->pendingArchiveMaterialName = $item->material_name;
        $this->showArchiveConfirmationModal = true;
    }

    public function cancelArchiveConfirmation(): void
    {
        $this->pendingArchiveMaterialId = null;
        $this->pendingArchiveMaterialName = '';
        $this->showArchiveConfirmationModal = false;
    }

    public function confirmArchiveMaterial(InventoryService $inventoryService): void
    {
        Gate::authorize('manage', Inventory::class);
        abort_if($this->pendingArchiveMaterialId === null, 404);
        $this->clearMessages();

        $item = Inventory::query()->active()->findOrFail($this->pendingArchiveMaterialId);

        $inventoryService->archiveMaterial($item, auth()->user());

        $this->successMessage = "{$item->material_name} was archived. You can bring it back from Archived Materials.";
        $this->cancelArchiveConfirmation();
        $this->reload();
    }

    private function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    private function reload(): void
    {
        $search = mb_strtolower(trim($this->search));

        $this->inventory = app(InventoryService::class)
            ->getInventoryList()
            ->when($search !== '', fn ($items) => $items
                ->filter(fn (Inventory $item): bool => str_contains(mb_strtolower($item->material_name), $search))
                ->values());
        $this->adjustmentRequests = app(InventoryService::class)
            ->getOpenAdjustmentRequests(auth()->user());
    }
};
?>

@php
    $inventoryRoutePrefix = auth()->user()->role === 'owner' ? 'owner' : 'cashier';
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="cashier-inventory-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Inventory</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Manage materials, review staff usage reports, and record stock changes.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route($inventoryRoutePrefix.'.inventory.create') }}" wire:navigate class="ps-button-primary">Add material</a>
            <a href="{{ route($inventoryRoutePrefix.'.inventory.archive') }}" wire:navigate class="ps-button-secondary">Archived materials</a>
        </div>
    </header>

    @if ($successMessage !== '')
        <div role="status" class="flex items-start gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            <span aria-hidden="true" class="font-bold text-emerald-500">✓</span> {{ $successMessage }}
        </div>
    @endif

    @if ($errorMessage !== '')
        <div role="alert" class="flex items-start gap-2 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            <span aria-hidden="true" class="font-bold text-red-500">✕</span> {{ $errorMessage }}
        </div>
    @endif

    <section class="ps-glass overflow-hidden" aria-labelledby="inventory-list-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <h2 id="inventory-list-heading" class="text-lg font-semibold text-[var(--ps-text)]">Materials <span class="font-normal text-[var(--ps-muted)]">({{ $inventory->count() }})</span></h2>
            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-xs" placeholder="Search materials" aria-label="Search materials">
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                <li wire:key="inventory-item-{{ $item->inventory_id }}" class="grid gap-4 px-5 py-4 sm:px-6">
                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center sm:gap-6">
                        <div class="grid gap-0.5">
                            <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                            <p class="text-xs text-[var(--ps-muted)]">Low-stock level: {{ $fmt($item->low_stock_level) }} {{ $item->unit }}</p>
                        </div>
                        <p class="text-lg font-semibold tabular-nums text-[var(--ps-text)]">{{ $fmt($item->quantity) }} <span class="text-sm font-normal text-[var(--ps-muted)]">{{ $item->unit }}</span></p>
                        <x-status-badge :tone="$item->stockStatusTone()" :label="$item->stockStatusLabel()" />
                    </div>

                    @if ($editingId === $item->inventory_id)
                        <form wire:submit="saveEdit" class="grid gap-3 rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-surface)] p-4 md:grid-cols-[2fr_1fr_1fr_auto] md:items-start">
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_name_{{ $item->inventory_id }}">Material name</label>
                                <input id="edit_name_{{ $item->inventory_id }}" type="text" wire:model="edit_name" class="ps-input" maxlength="150" required>
                                @error('edit_name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_unit_{{ $item->inventory_id }}">Unit</label>
                                <input id="edit_unit_{{ $item->inventory_id }}" type="text" wire:model="edit_unit" class="ps-input" maxlength="30" required>
                                @error('edit_unit') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid gap-1">
                                <label class="text-sm font-medium text-[var(--ps-text)]" for="edit_low_{{ $item->inventory_id }}">Low-stock level</label>
                                <input id="edit_low_{{ $item->inventory_id }}" type="number" min="0" step="0.001" wire:model="edit_low_stock_level" class="ps-input" required>
                                @error('edit_low_stock_level') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <fieldset class="grid gap-2 md:col-span-3">
                                <legend class="text-sm font-medium text-[var(--ps-text)]">Who uses this material?</legend>
                                <label class="flex items-center gap-2 text-sm text-[var(--ps-text)]">
                                    <flux:checkbox wire:model="editAllSpecialties" />
                                    <span>All specialties</span>
                                </label>
                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach (StaffSpecialtyType::cases() as $specialty)
                                        <label wire:key="edit-inventory-specialty-{{ $item->inventory_id }}-{{ $specialty->value }}" class="flex items-center gap-2 text-sm text-[var(--ps-text)]">
                                            <flux:checkbox wire:model="edit_specialties" value="{{ $specialty->value }}" :disabled="$editAllSpecialties" />
                                            <span>{{ $specialty->label() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('edit_specialties') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                                @error('edit_specialties.*') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            </fieldset>
                            <div class="flex gap-2 md:pt-6">
                                <button type="submit" class="ps-button-primary">Save</button>
                                <button type="button" wire:click="cancelEdit" class="ps-button-secondary">Cancel</button>
                            </div>
                        </form>
                    @endif

                    <div class="grid gap-3 border-t border-[var(--ps-border)] pt-4 lg:grid-cols-[1fr_1.4fr_auto] lg:items-start">
                        <div class="grid gap-1">
                            <div class="flex gap-2">
                                <input type="number" min="0" step="0.001" wire:model="stockAdd.{{ $item->inventory_id }}" class="ps-input" placeholder="How many to add" aria-label="Quantity to add to {{ $item->material_name }}">
                                <button type="button" wire:click="addStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary shrink-0">Add stock</button>
                            </div>
                            @error("stockAdd.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-1">
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input type="number" min="0" step="0.001" wire:model="deduct.{{ $item->inventory_id }}" class="ps-input sm:max-w-40" placeholder="How many to deduct" aria-label="Quantity to deduct from {{ $item->material_name }}">
                                <input type="text" wire:model="deductNote.{{ $item->inventory_id }}" class="ps-input" maxlength="255" placeholder="Reason (optional), e.g. used for order #12" aria-label="Reason for deducting {{ $item->material_name }}">
                                <button type="button" wire:click="deductStock({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-primary shrink-0">Deduct</button>
                            </div>
                            @error("deduct.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="startEdit({{ $item->inventory_id }})" class="ps-button-ghost">Edit</button>
                            <button
                                type="button"
                                wire:click="requestArchiveMaterial({{ $item->inventory_id }})"
                                wire:loading.attr="disabled"
                                wire:target="requestArchiveMaterial"
                                class="ps-button-ghost text-amber-600 dark:text-amber-400"
                            >Archive</button>
                        </div>
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">
                    {{ $search !== '' ? 'No materials match your search.' : 'No materials yet. Use Add material to create the first one.' }}
                </li>
            @endforelse
        </ul>
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="usage-adjustments-heading">
        <header class="grid gap-1 border-b border-[var(--ps-border)] px-5 py-5 sm:px-6">
            <h2 id="usage-adjustments-heading" class="text-lg font-semibold text-[var(--ps-text)]">Staff Usage Reports</h2>
            <p class="text-sm text-[var(--ps-muted)]">Start each report before completing it. Stock changes only when you complete an in-progress report.</p>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($adjustmentRequests as $request)
                <li wire:key="inventory-adjustment-{{ $request->request_id }}" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            {{ $request->inventory->material_name }} · {{ $fmt($request->quantity) }} {{ $request->inventory->unit }}
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">Reported by {{ $request->staff->name }} · {{ $request->created_at->format('M j, Y g:i A') }}</p>
                        @if ($request->remarks)
                            <p class="text-sm text-[var(--ps-muted)]">Remarks: {{ $request->remarks }}</p>
                        @endif
                        @if ($request->startedBy)
                            <p class="text-xs text-[var(--ps-muted)]">In progress with {{ $request->startedBy->name }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-status-badge
                            :tone="$request->status === InventoryAdjustmentRequest::STATUS_IN_PROGRESS ? 'progress' : 'warning'"
                            :label="$request->status === InventoryAdjustmentRequest::STATUS_IN_PROGRESS ? 'In Progress' : 'Pending Review'"
                        />
                        @if ($request->status === InventoryAdjustmentRequest::STATUS_PENDING)
                            <button type="button" wire:click="startAdjustmentReview({{ $request->request_id }})" wire:loading.attr="disabled" class="ps-button-secondary">Mark in progress</button>
                        @else
                            <button type="button" wire:click="completeAdjustment({{ $request->request_id }})" wire:loading.attr="disabled" class="ps-button-primary">Complete and deduct</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No staff usage reports are waiting for review.</li>
            @endforelse
        </ul>
    </section>

    <flux:modal wire:model.self="showArchiveConfirmationModal" @cancel="cancelArchiveConfirmation" class="max-w-lg">
        <div class="grid gap-5">
            <div class="grid gap-1">
                <flux:heading size="lg">Archive {{ $pendingArchiveMaterialName }}?</flux:heading>
                <flux:text>It will leave the active list, but its history will be kept and you can restore it later.</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <button type="button" wire:click="cancelArchiveConfirmation" class="ps-button-secondary">Cancel</button>
                </flux:modal.close>
                <button type="button" wire:click="confirmArchiveMaterial" wire:loading.attr="disabled" wire:target="confirmArchiveMaterial" class="ps-button-primary">Archive material</button>
            </div>
        </div>
    </flux:modal>
</div>