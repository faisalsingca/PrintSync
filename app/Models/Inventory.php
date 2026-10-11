<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $inventory_id
 * @property string $material_name
 * @property string $quantity
 * @property string $unit
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'inventory', key: 'inventory_id')]
#[Fillable(['material_name', 'quantity', 'unit', 'status'])]
class Inventory extends Model
{
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
        ];
    }
}
