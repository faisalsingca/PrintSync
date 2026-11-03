<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAlert;
use App\Models\StockIn;
use App\Models\User;
use App\StaffSpecialtyType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Create a new inventory material. Its status is worked out from the quantity.
     *
     * @param  array{material_name: string, quantity: string, unit: string, low_stock_level?: string, specialties?: list<string>}  $attributes
     */
    public function createMaterial(array $attributes, ?User $actor = null): Inventory
    {
        return DB::transaction(function () use ($attributes, $actor): Inventory {
            $inventory = new Inventory([
                'material_name' => $attributes['material_name'],
                'quantity' => $attributes['quantity'],
                'unit' => $attributes['unit'],
                'low_stock_level' => $attributes['low_stock_level'] ?? '10',
            ]);

            $inventory->status = $inventory->stockStatus();
            $inventory->save();
            $specialties = $attributes['specialties'] ?? ['all'];
            $this->validateSpecialties($specialties);
            $this->replaceSpecialties($inventory, $specialties);

            $this->audit()->record(
                $actor,
                'inventory.created',
                "Added material \"{$inventory->material_name}\" with {$this->plain($inventory->quantity)} {$inventory->unit} (low-stock level {$this->plain($inventory->low_stock_level)}).",
                'inventory',
                $inventory->inventory_id,
                $inventory->material_name,
                [
                    'quantity' => $this->plain($inventory->quantity),
                    'unit' => $inventory->unit,
                    'low_stock_level' => $this->plain($inventory->low_stock_level),
                ],
            );

            return $inventory;
        });
    }

    /**
     * Edit a material's details. Quantity changes should go through addStock / deductStock so each one is logged;
     * a quantity passed here is still accepted and recorded.
     *
     * @param  array{material_name?: string, quantity?: string, unit?: string, low_stock_level?: string}  $attributes
     */
    public function updateMaterial(Inventory $inventory, array $attributes, ?User $actor = null): Inventory
    {
        return DB::transaction(function () use ($inventory, $attributes, $actor): Inventory {
            $this->ensureActive($inventory);

            $labels = [
                'material_name' => 'name',
                'quantity' => 'quantity',
                'unit' => 'unit',
                'low_stock_level' => 'low-stock level',
            ];

            $changes = [];
            $before = $inventory->only(array_keys($labels));

            $inventory->fill(array_intersect_key($attributes, $labels));

            foreach (array_keys($labels) as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $old = $this->plain($before[$field]);
                $new = $this->plain($inventory->{$field});

                if ($old !== $new) {
                    $changes[$labels[$field]] = ['from' => $old, 'to' => $new];
                }
            }

            $inventory->status = $inventory->stockStatus();
            $inventory->save();

            if ($changes !== []) {
                $summary = collect($changes)
                    ->map(fn (array $change, string $label): string => "{$label}: {$change['from']} → {$change['to']}")
                    ->implode('; ');

                $this->audit()->record(
                    $actor,
                    'inventory.updated',
                    "Edited \"{$inventory->material_name}\" ({$summary}).",
                    'inventory',
                    $inventory->inventory_id,
                    $inventory->material_name,
                    ['changes' => $changes],
                );
            }

            return $inventory;
        });
    }

    /**
     * Replace the specialties allowed to report use of a material.
     *
     * @param  list<string>  $specialties
     */
    public function updateMaterialSpecialties(Inventory $inventory, array $specialties, ?User $actor = null): void
    {
        DB::transaction(function () use ($inventory, $specialties, $actor): void {
            $this->ensureActive($inventory);
            $this->validateSpecialties($specialties);

            $before = $inventory->specialties()->orderBy('specialty')->pluck('specialty')->all();
            $this->replaceSpecialties($inventory, $specialties);
            $after = $inventory->specialties()->orderBy('specialty')->pluck('specialty')->all();

            if ($before !== $after) {
                $this->audit()->record(
                    $actor,
                    'inventory.specialties_updated',
                    "Updated which staff specialties can use \"{$inventory->material_name}\".",
                    'inventory',
                    $inventory->inventory_id,
                    $inventory->material_name,
                    ['from' => $before, 'to' => $after],
                );
            }
        });
    }

    /**
     * Archive a material instead of deleting it. History is kept and it can be restored.
     */
    public function archiveMaterial(Inventory $inventory, ?User $actor = null): Inventory
    {
        return DB::transaction(function () use ($inventory, $actor): Inventory {
            $this->ensureActive($inventory);

            if ($inventory->adjustmentRequests()
                ->whereIn('status', [
                    InventoryAdjustmentRequest::STATUS_PENDING,
                    InventoryAdjustmentRequest::STATUS_IN_PROGRESS,
                ])
                ->exists()) {
                throw ValidationException::withMessages([
                    'inventory' => 'Resolve open usage reports before archiving this material.',
                ]);
            }

            $inventory->forceFill(['archived_at' => now()])->save();

            $this->audit()->record(
                $actor,
                'inventory.archived',
                "Archived \"{$inventory->material_name}\" ({$this->plain($inventory->quantity)} {$inventory->unit} left).",
                'inventory',
                $inventory->inventory_id,
                $inventory->material_name,
                ['quantity' => $this->plain($inventory->quantity), 'unit' => $inventory->unit],
            );

            return $inventory;
        });
    }

    /**
     * Bring an archived material back.
     */
    public function restoreMaterial(Inventory $inventory, ?User $actor = null): Inventory
    {
        return DB::transaction(function () use ($inventory, $actor): Inventory {
            if (! $inventory->isArchived()) {
                return $inventory;
            }

            $inventory->forceFill(['archived_at' => null])->save();

            $this->audit()->record(
                $actor,
                'inventory.restored',
                "Restored \"{$inventory->material_name}\" from the archive ({$this->plain($inventory->quantity)} {$inventory->unit}).",
                'inventory',
                $inventory->inventory_id,
                $inventory->material_name,
                ['quantity' => $this->plain($inventory->quantity), 'unit' => $inventory->unit],
            );

            return $inventory;
        });
    }

    /**
     * Add stock to an inventory item and record the stock-in transaction.
     */
    public function addStock(
        Inventory $inventory,
        User $staff,
        string $quantityAdded,
        ?CarbonInterface $dateAdded = null,
    ): StockIn {
        return DB::transaction(function () use ($inventory, $staff, $quantityAdded, $dateAdded): StockIn {
            $this->ensureActive($inventory);

            $inventory->increment('quantity', $quantityAdded);
            $inventory->refresh();
            $inventory->forceFill(['status' => $inventory->stockStatus()])->save();

            $stockIn = StockIn::create([
                'inventory_id' => $inventory->inventory_id,
                'staff_id' => $staff->id,
                'quantity_added' => $quantityAdded,
                'date_added' => $dateAdded ?? now(),
            ]);

            $this->audit()->record(
                $staff,
                'inventory.stock_added',
                "Added {$this->plain($quantityAdded)} {$inventory->unit} to \"{$inventory->material_name}\". Now {$this->plain($inventory->quantity)} {$inventory->unit}.",
                'inventory',
                $inventory->inventory_id,
                $inventory->material_name,
                ['quantity_added' => $this->plain($quantityAdded), 'quantity_after' => $this->plain($inventory->quantity), 'unit' => $inventory->unit],
            );

            return $stockIn;
        });
    }

    /**
     * Deduct stock and record an automatic low-stock alert when the actual stock crosses its threshold.
     *
     * @throws ValidationException when more than the remaining quantity is deducted
     */
    public function deductStock(Inventory $inventory, User $staff, string $quantity, ?string $note = null): Inventory
    {
        return DB::transaction(function () use ($inventory, $staff, $quantity, $note): Inventory {
            $locked = Inventory::query()
                ->lockForUpdate()
                ->findOrFail($inventory->inventory_id);

            $this->ensureActive($locked);

            if (round((float) $locked->quantity - (float) $quantity, 3) < 0) {
                throw ValidationException::withMessages([
                    'deduct' => "Only {$this->plain($locked->quantity)} {$locked->unit} left, so you cannot deduct {$this->plain($quantity)}.",
                ]);
            }

            $before = $locked->stockStatus();

            $locked->decrement('quantity', $quantity);
            $locked->refresh();

            $after = $locked->stockStatus();
            $locked->forceFill(['status' => $after])->save();

            $noteText = $note !== null && trim($note) !== '' ? ' Note: '.trim($note) : '';

            $this->audit()->record(
                $staff,
                'inventory.stock_deducted',
                "Deducted {$this->plain($quantity)} {$locked->unit} from \"{$locked->material_name}\". Now {$this->plain($locked->quantity)} {$locked->unit}.{$noteText}",
                'inventory',
                $locked->inventory_id,
                $locked->material_name,
                array_filter([
                    'quantity_deducted' => $this->plain($quantity),
                    'quantity_after' => $this->plain($locked->quantity),
                    'unit' => $locked->unit,
                    'note' => $noteText === '' ? null : trim($note),
                ]),
            );

            if ($after !== Inventory::STATUS_IN_STOCK && $after !== $before) {
                $this->makeAlert(
                    $locked,
                    $staff,
                    $after === Inventory::STATUS_OUT_OF_STOCK
                        ? 'Automatic alert: out of stock after a deduction.'
                        : "Automatic alert: stock fell to {$this->plain($locked->quantity)} {$locked->unit}.",
                );
            }

            return $locked;
        });
    }

    /**
     * Record material use without changing stock. An owner or cashier reviews and completes the request.
     */
    public function createAdjustmentRequest(
        Inventory $inventory,
        User $staff,
        string $quantity,
        ?string $remarks = null,
    ): InventoryAdjustmentRequest {
        return DB::transaction(function () use ($inventory, $staff, $quantity, $remarks): InventoryAdjustmentRequest {
            $lockedInventory = Inventory::query()
                ->lockForUpdate()
                ->findOrFail($inventory->inventory_id);

            $this->ensureActive($lockedInventory);

            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Enter a quantity greater than zero.',
                ]);
            }

            $hasOpenRequest = $lockedInventory->adjustmentRequests()
                ->where('staff_id', $staff->id)
                ->whereIn('status', [
                    InventoryAdjustmentRequest::STATUS_PENDING,
                    InventoryAdjustmentRequest::STATUS_IN_PROGRESS,
                ])
                ->exists();

            if ($hasOpenRequest) {
                throw ValidationException::withMessages([
                    'quantity' => 'You already have an open usage report for this material.',
                ]);
            }

            $request = InventoryAdjustmentRequest::create([
                'inventory_id' => $lockedInventory->inventory_id,
                'staff_id' => $staff->id,
                'quantity' => $quantity,
                'remarks' => $remarks,
                'status' => InventoryAdjustmentRequest::STATUS_PENDING,
            ]);

            $this->audit()->record(
                $staff,
                'inventory.adjustment_requested',
                "Reported use of {$this->plain($quantity)} {$lockedInventory->unit} of \"{$lockedInventory->material_name}\".",
                InventoryAdjustmentRequest::class,
                $request->request_id,
                $lockedInventory->material_name,
                array_filter([
                    'quantity' => $this->plain($quantity),
                    'remarks' => $remarks,
                ]),
            );

            return $request;
        });
    }

    /**
     * Mark an open usage report as being reviewed.
     */
    public function startAdjustmentRequest(InventoryAdjustmentRequest $request, User $actor): InventoryAdjustmentRequest
    {
        return DB::transaction(function () use ($request, $actor): InventoryAdjustmentRequest {
            $lockedRequest = InventoryAdjustmentRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->request_id);

            if ($lockedRequest->status !== InventoryAdjustmentRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'adjustment' => 'Only pending usage reports can be started.',
                ]);
            }

            $lockedRequest->forceFill([
                'status' => InventoryAdjustmentRequest::STATUS_IN_PROGRESS,
                'started_by' => $actor->id,
                'started_at' => now(),
            ])->save();

            $this->audit()->record(
                $actor,
                'inventory.adjustment_started',
                "Started reviewing usage report #{$lockedRequest->request_id}.",
                InventoryAdjustmentRequest::class,
                $lockedRequest->request_id,
                "Usage report #{$lockedRequest->request_id}",
            );

            return $lockedRequest;
        });
    }

    /**
     * Deduct the reported quantity and close the usage report.
     *
     * @throws ValidationException when the reported quantity exceeds available stock
     */
    public function completeAdjustmentRequest(
        InventoryAdjustmentRequest $request,
        User $actor,
    ): InventoryAdjustmentRequest {
        return DB::transaction(function () use ($request, $actor): InventoryAdjustmentRequest {
            $lockedRequest = InventoryAdjustmentRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->request_id);

            if ($lockedRequest->status !== InventoryAdjustmentRequest::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'adjustment' => 'Start the usage report before completing it.',
                ]);
            }

            $inventory = Inventory::query()->findOrFail($lockedRequest->inventory_id);

            $this->deductStock(
                $inventory,
                $actor,
                $lockedRequest->quantity,
                $lockedRequest->remarks,
            );

            $lockedRequest->forceFill([
                'status' => InventoryAdjustmentRequest::STATUS_COMPLETED,
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ])->save();

            $this->audit()->record(
                $actor,
                'inventory.adjustment_completed',
                "Completed usage report #{$lockedRequest->request_id}.",
                InventoryAdjustmentRequest::class,
                $lockedRequest->request_id,
                "Usage report #{$lockedRequest->request_id}",
            );

            return $lockedRequest;
        });
    }

    /**
     * Active (not archived) materials ordered by name.
     *
     * @return Collection<int, Inventory>
     */
    public function getInventoryList(): Collection
    {
        return Inventory::query()
            ->active()
            ->with('specialties')
            ->orderBy('material_name')
            ->get();
    }

    /**
     * Materials used by the staff member's assigned specialties.
     *
     * @return Collection<int, Inventory>
     */
    public function getStaffInventoryList(User $staff): Collection
    {
        $specialties = $staff->specialties()->pluck('specialty')->all();

        return Inventory::query()
            ->active()
            ->whereHas('specialties', function (Builder $query) use ($specialties): void {
                $query->where('specialty', 'all');

                if ($specialties !== []) {
                    $query->orWhereIn('specialty', $specialties);
                }
            })
            ->with('specialties')
            ->orderBy('material_name')
            ->get();
    }

    /**
     * Open adjustment requests for staff, or all staff when viewed by a manager.
     *
     * @return Collection<int, InventoryAdjustmentRequest>
     */
    public function getOpenAdjustmentRequests(User $viewer): Collection
    {
        return InventoryAdjustmentRequest::query()
            ->whereIn('status', [
                InventoryAdjustmentRequest::STATUS_PENDING,
                InventoryAdjustmentRequest::STATUS_IN_PROGRESS,
            ])
            ->when($viewer->role === 'staff', fn (Builder $query) => $query->where('staff_id', $viewer->id))
            ->with(['inventory', 'staff', 'startedBy'])
            ->latest('created_at')
            ->latest('request_id')
            ->get();
    }

    /**
     * Record a low-stock alert for internal callers.
     */
    public function createLowStockAlert(
        Inventory $inventory,
        User $staff,
        ?string $remarks = null,
        ?CarbonInterface $alertDate = null,
    ): InventoryAlert {
        $alert = $this->makeAlert($inventory, $staff, $remarks, $alertDate);
        $remarkText = $remarks !== null && trim($remarks) !== '' ? ' Remarks: '.trim($remarks) : '';

        $this->audit()->record(
            $staff,
            'inventory.low_stock_reported',
            "Reported \"{$inventory->material_name}\" as low on stock.{$remarkText}",
            'inventory',
            $inventory->inventory_id,
            $inventory->material_name,
            array_filter(['remarks' => $remarkText === '' ? null : trim($remarks)]),
        );

        return $alert;
    }

    /**
     * Archived materials, most recently archived first.
     *
     * @return Collection<int, Inventory>
     */
    public function getArchivedInventoryList(): Collection
    {
        return Inventory::query()
            ->archived()
            ->orderByDesc('archived_at')
            ->get();
    }

    /**
     * Retrieve recorded low-stock alerts with their current inventory details.
     *
     * @return Collection<int, InventoryAlert>
     */
    public function getLowStockAlerts(): Collection
    {
        return InventoryAlert::query()
            ->where('alert_type', 'low_stock')
            ->whereHas('inventory', fn (Builder $query) => $query->whereNull('archived_at'))
            ->with('inventory')
            ->latest('alert_date')
            ->latest('alert_id')
            ->get();
    }

    /**
     * Low-stock reports the cashier still has to handle, newest first.
     *
     * A report counts as handled once stock has been added to that material
     * after the report was made.
     *
     * @return Collection<int, InventoryAlert>
     */
    public function getOpenLowStockAlerts(): Collection
    {
        return $this->openLowStockAlertsQuery()
            ->with(['inventory', 'staff.specialties'])
            ->latest('alert_date')
            ->latest('alert_id')
            ->get();
    }

    /**
     * Whether this staff member already has an unhandled low-stock report for the material.
     */
    public function hasOpenAlertFrom(Inventory $inventory, User $staff): bool
    {
        return $this->openLowStockAlertsQuery()
            ->where('inventory_id', $inventory->inventory_id)
            ->where('staff_id', $staff->id)
            ->exists();
    }

    /**
     * Retrieve inventory and recorded low-stock alert counts.
     *
     * @return array{inventory_count: int, low_stock_alert_count: int, open_low_stock_alert_count: int, archived_count: int}
     */
    public function getInventorySummary(): array
    {
        return [
            'inventory_count' => Inventory::query()->active()->count(),
            'archived_count' => Inventory::query()->archived()->count(),
            'low_stock_alert_count' => InventoryAlert::query()
                ->where('alert_type', 'low_stock')
                ->count(),
            'open_low_stock_alert_count' => $this->openLowStockAlertsQuery()->count(),
        ];
    }

    /**
     * @return Builder<InventoryAlert>
     */
    private function openLowStockAlertsQuery(): Builder
    {
        return InventoryAlert::query()
            ->where('alert_type', 'low_stock')
            ->whereHas('inventory', fn (Builder $query) => $query->whereNull('archived_at'))
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('stock_in')
                    ->whereColumn('stock_in.inventory_id', 'inventory_alerts.inventory_id')
                    ->whereColumn('stock_in.date_added', '>=', 'inventory_alerts.alert_date');
            });
    }

    private function makeAlert(
        Inventory $inventory,
        User $staff,
        ?string $remarks = null,
        ?CarbonInterface $alertDate = null,
    ): InventoryAlert {
        return InventoryAlert::create([
            'inventory_id' => $inventory->inventory_id,
            'staff_id' => $staff->id,
            'alert_type' => 'low_stock',
            'alert_date' => $alertDate ?? now(),
            'remarks' => $remarks,
        ]);
    }

    /**
     * @throws ValidationException when the material is archived
     */
    private function ensureActive(Inventory $inventory): void
    {
        if ($inventory->isArchived()) {
            throw ValidationException::withMessages([
                'inventory' => "\"{$inventory->material_name}\" is archived. Restore it first.",
            ]);
        }
    }

    /**
     * @param  list<string>  $specialties
     */
    private function replaceSpecialties(Inventory $inventory, array $specialties): void
    {
        $specialties = array_values(array_unique($specialties));

        if (in_array('all', $specialties, true)) {
            $specialties = ['all'];
        }

        $inventory->specialties()->delete();

        $inventory->specialties()->createMany(array_map(
            fn (string $specialty): array => ['specialty' => $specialty],
            $specialties,
        ));
    }

    /**
     * @param  list<string>  $specialties
     */
    private function validateSpecialties(array $specialties): void
    {
        if ($specialties === []) {
            throw ValidationException::withMessages([
                'specialties' => 'Select at least one staff specialty or all specialties.',
            ]);
        }

        foreach ($specialties as $specialty) {
            if (! is_string($specialty) || ($specialty !== 'all' && StaffSpecialtyType::tryFrom($specialty) === null)) {
                throw ValidationException::withMessages([
                    'specialties' => 'Select only documented staff specialties.',
                ]);
            }
        }
    }

    private function audit(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    /**
     * Show a quantity without trailing zeros (12.000 becomes 12).
     */
    private function plain(string|float|null $number): string
    {
        if ($number === null || ! is_numeric($number)) {
            return (string) $number;
        }

        return rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
    }
}
