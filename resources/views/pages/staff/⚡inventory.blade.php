<?php

use App\Models\Inventory;
use App\Models\InventoryAdjustmentRequest;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Report Material Use')] class extends Component
{
    public Collection $inventory;

    public Collection $adjustmentRequests;

    public string $search = '';

    public array $adjustmentQuantities = [];

    public array $adjustmentRemarks = [];

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
     * Submit a material-use report for manager review.
     */
    public function reportMaterialUse(int $inventoryId, InventoryService $inventoryService): void
    {
        $this->successMessage = '';
        $item = Inventory::query()->findOrFail($inventoryId);
        Gate::authorize('createAdjustmentRequest', $item);

        $this->validate([
            "adjustmentQuantities.$inventoryId" => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            "adjustmentRemarks.$inventoryId" => ['nullable', 'string', 'max:500'],
        ], [], [
            "adjustmentQuantities.$inventoryId" => 'quantity used',
            "adjustmentRemarks.$inventoryId" => 'remarks',
        ]);

        $remarks = trim((string) ($this->adjustmentRemarks[$inventoryId] ?? ''));

        $inventoryService->createAdjustmentRequest(
            $item,
            auth()->user(),
            (string) $this->adjustmentQuantities[$inventoryId],
            $remarks === '' ? null : $remarks,
        );

        $this->successMessage = "Your use of {$item->material_name} was reported for review.";
        unset($this->adjustmentQuantities[$inventoryId], $this->adjustmentRemarks[$inventoryId]);
        $this->reload();
    }

    private function reload(): void
    {
        $service = app(InventoryService::class);
        $search = mb_strtolower(trim($this->search));

        $staff = auth()->user();
        $this->inventory = $service
            ->getStaffInventoryList($staff)
            ->when($search !== '', fn ($items) => $items
                ->filter(fn (Inventory $item): bool => str_contains(mb_strtolower($item->material_name), $search))
                ->values());

        $this->adjustmentRequests = $service->getOpenAdjustmentRequests($staff);
    }
};
?>

@php
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="staff-inventory-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Report Material Use</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">
            Report the quantity you used and add a remark if needed. An owner or cashier will review the report and update the stock.
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
    @error('quantity')
        <div role="alert" class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $message }}
        </div>
    @enderror

    <section class="ps-glass overflow-hidden" aria-labelledby="inventory-list-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <h2 id="inventory-list-heading" class="text-lg font-semibold text-[var(--ps-text)]">Materials</h2>
            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-xs" placeholder="Search materials" aria-label="Search materials">
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                @php
                    $state = $item->stockStatus();
                    $quantityError = 'adjustmentQuantities.'.$item->inventory_id;
                    $alreadyReported = $adjustmentRequests->contains(
                        fn (InventoryAdjustmentRequest $request): bool => $request->inventory_id === $item->inventory_id,
                    );
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
                            <p class="text-sm font-medium text-amber-500">Reported. Waiting for review.</p>
                            @error($quantityError) <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                        @else
                            <div class="grid gap-2 sm:grid-cols-[minmax(8rem,0.35fr)_minmax(0,1fr)_auto]">
                                <input type="number" min="0.001" step="0.001" wire:model="adjustmentQuantities.{{ $item->inventory_id }}" class="ps-input" placeholder="Quantity used" aria-label="Quantity of {{ $item->material_name }} used">
                                <input type="text" wire:model="adjustmentRemarks.{{ $item->inventory_id }}" class="ps-input" maxlength="500" placeholder="Remarks (optional)" aria-label="Usage remarks for {{ $item->material_name }}">
                                <button type="button" wire:click="reportMaterialUse({{ $item->inventory_id }})" wire:loading.attr="disabled" class="ps-button-secondary shrink-0">Report use</button>
                            </div>
                            @error($quantityError) <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            @error("adjustmentRemarks.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
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

    <section class="ps-glass overflow-hidden" aria-labelledby="usage-reports-heading">
        <header class="grid gap-1 border-b border-[var(--ps-border)] px-5 py-5 sm:px-6">
            <h2 id="usage-reports-heading" class="text-lg font-semibold text-[var(--ps-text)]">Usage Reports</h2>
            <p class="text-sm text-[var(--ps-muted)]">Completed reports are removed from this active list.</p>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($adjustmentRequests as $request)
                <li wire:key="staff-usage-report-{{ $request->request_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">{{ $request->inventory->material_name }} · {{ $fmt($request->quantity) }} {{ $request->inventory->unit }}</h3>
                        @if ($request->remarks)
                            <p class="text-sm text-[var(--ps-muted)]">{{ $request->remarks }}</p>
                        @endif
                    </div>
                    <x-status-badge
                        :tone="$request->status === InventoryAdjustmentRequest::STATUS_IN_PROGRESS ? 'progress' : 'warning'"
                        :label="$request->status === InventoryAdjustmentRequest::STATUS_IN_PROGRESS ? 'In Progress' : 'Pending Review'"
                    />
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No usage reports are waiting for review.</li>
            @endforelse
        </ul>
    </section>
</div>