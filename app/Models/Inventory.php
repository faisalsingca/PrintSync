<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $inventory_id
 * @property string $material_name
 * @property string $quantity
 * @property string $unit
 * @property string $low_stock_level
 * @property string $status
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'inventory', key: 'inventory_id')]
#[Fillable(['material_name', 'quantity', 'unit', 'low_stock_level', 'status'])]
class Inventory extends Model
{
    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_LOW_STOCK = 'low_stock';

    public const STATUS_OUT_OF_STOCK = 'out_of_stock';

    /**
     * Materials that are in use (not archived).
     *
     * @param  Builder<Inventory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Materials that were archived.
     *
     * @param  Builder<Inventory>  $query
     */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Work out the stock status from the current quantity.
     */
    public function stockStatus(): string
    {
        $quantity = (float) $this->quantity;

        if ($quantity <= 0) {
            return self::STATUS_OUT_OF_STOCK;
        }

        if ($quantity <= (float) $this->low_stock_level) {
            return self::STATUS_LOW_STOCK;
        }

        return self::STATUS_IN_STOCK;
    }

    /**
     * The badge tone for the current stock status (success, warning or danger).
     */
    public function stockStatusTone(): string
    {
        return match ($this->stockStatus()) {
            self::STATUS_OUT_OF_STOCK => 'danger',
            self::STATUS_LOW_STOCK => 'warning',
            default => 'success',
        };
    }

    /**
     * The label shown next to the status indicator.
     */
    public function stockStatusLabel(): string
    {
        return match ($this->stockStatus()) {
            self::STATUS_OUT_OF_STOCK => 'Out of Stock (No Stock)',
            self::STATUS_LOW_STOCK => 'Low Stock (Requires Restock)',
            default => 'In Stock (Available)',
        };
    }

    /**
     * Get the stock-in records for this inventory item.
     */
    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'inventory_id', 'inventory_id');
    }

    /**
     * Get the low-stock alerts for this inventory item.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(InventoryAlert::class, 'inventory_id', 'inventory_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'low_stock_level' => 'decimal:3',
            'archived_at' => 'datetime',
        ];
    }
}
