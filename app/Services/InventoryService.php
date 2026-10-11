<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryAlert;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Create a new inventory material.
     *
     * @param  array{material_name: string, quantity: string, unit: string, status: string}  $attributes
     */
    public function createMaterial(array $attributes): Inventory
    {
        return Inventory::create([
            'material_name' => $attributes['material_name'],
            'quantity' => $attributes['quantity'],
            'unit' => $attributes['unit'],
            'status' => $attributes['status'],
        ]);
    }

    /**
     * Update an inventory material's name, quantity, unit, and status.
     *
     * @param  array{material_name: string, quantity: string, unit: string, status: string}  $attributes
     */
    public function updateMaterial(Inventory $inventory, array $attributes): Inventory
    {
        $inventory->fill([
            'material_name' => $attributes['material_name'],
            'quantity' => $attributes['quantity'],
            'unit' => $attributes['unit'],
            'status' => $attributes['status'],
        ])->save();

        return $inventory;
    }

    /**
     * Delete a material that has no stock-in or low-stock history.
     */
    public function deleteMaterial(Inventory $inventory): void
    {
        if ($inventory->stockIns()->exists() || $inventory->alerts()->exists()) {
            throw ValidationException::withMessages([
                'inventory' => 'This material has stock-in or low-stock records and cannot be deleted. Set its status to unavailable instead.',
            ]);
        }

        $inventory->delete();
    }

    /**
     * Add stock to an inventory item and record the stock-in transaction.
     */
    public function addStock(
        Inventory $inventory,
        User $staff,
        string $quantityAdded,
        ?Carbon $dateAdded = null,
    ): StockIn {
        return DB::transaction(function () use ($inventory, $staff, $quantityAdded, $dateAdded): StockIn {
            $inventory->increment('quantity', $quantityAdded);

            return StockIn::create([
                'inventory_id' => $inventory->inventory_id,
                'staff_id' => $staff->id,
                'quantity_added' => $quantityAdded,
                'date_added' => $dateAdded ?? now(),
            ]);
        });
    }

    /**
     * Create a low-stock alert for an inventory item.
     */
    public function createLowStockAlert(
        Inventory $inventory,
        User $staff,
        ?string $remarks = null,
        ?Carbon $alertDate = null,
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
     * Retrieve the inventory list ordered by material name.
     *
     * @return Collection<int, Inventory>
     */
    public function getInventoryList(): Collection
    {
        return Inventory::query()
            ->orderBy('material_name')
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
            ->with('inventory')
            ->latest('alert_date')
            ->latest('alert_id')
            ->get();
    }

    /**
     * Retrieve inventory and recorded low-stock alert counts.
     *
     * @return array{inventory_count: int, low_stock_alert_count: int}
     */
    public function getInventorySummary(): array
    {
        return [
            'inventory_count' => Inventory::query()->count(),
            'low_stock_alert_count' => InventoryAlert::query()
                ->where('alert_type', 'low_stock')
                ->count(),
        ];
    }
}
