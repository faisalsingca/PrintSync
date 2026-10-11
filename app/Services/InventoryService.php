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
     * Create a new inventory material. Its status is worked out from the quantity.
     *
     * @param  array{material_name: string, quantity: string, unit: string, low_stock_level?: string}  $attributes
     */
    public function createMaterial(array $attributes): Inventory
    {
        $inventory = new Inventory([
            'material_name' => $attributes['material_name'],
            'quantity' => $attributes['quantity'],
            'unit' => $attributes['unit'],
            'low_stock_level' => $attributes['low_stock_level'] ?? '10',
        ]);

        $inventory->status = $inventory->stockStatus();
        $inventory->save();

        return $inventory;
    }

    /**
     * Update an inventory material. Its status is worked out from the quantity.
     *
     * @param  array{material_name: string, quantity: string, unit: string}  $attributes
     */
    public function updateMaterial(Inventory $inventory, array $attributes): Inventory
    {
        $inventory->fill([
            'material_name' => $attributes['material_name'],
            'quantity' => $attributes['quantity'],
            'unit' => $attributes['unit'],
        ]);

        $inventory->status = $inventory->stockStatus();
        $inventory->save();

        return $inventory;
    }

    /**
     * Delete a material that has no stock-in or low-stock history.
     */
    public function deleteMaterial(Inventory $inventory): void
    {
        if ($inventory->stockIns()->exists() || $inventory->alerts()->exists()) {
            throw ValidationException::withMessages([
                'inventory' => 'This material has stock or alert records and cannot be deleted. Deduct its remaining stock instead.',
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
            $inventory->refresh();
            $inventory->forceFill(['status' => $inventory->stockStatus()])->save();

            return StockIn::create([
                'inventory_id' => $inventory->inventory_id,
                'staff_id' => $staff->id,
                'quantity_added' => $quantityAdded,
                'date_added' => $dateAdded ?? now(),
            ]);
        });
    }

    /**
     * Deduct stock that was used. Records a low-stock alert automatically when the
     * material drops into Low Stock or Out of Stock.
     *
     * @throws ValidationException when more than the remaining quantity is deducted
     */
    public function deductStock(Inventory $inventory, User $staff, string $quantity): Inventory
    {
        return DB::transaction(function () use ($inventory, $staff, $quantity): Inventory {
            $locked = Inventory::query()
                ->lockForUpdate()
                ->findOrFail($inventory->inventory_id);

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

            if ($after !== Inventory::STATUS_IN_STOCK && $after !== $before) {
                $this->createLowStockAlert(
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

    /**
     * Show a quantity without trailing zeros (12.000 becomes 12).
     */
    private function plain(string|float $number): string
    {
        return rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
    }
}