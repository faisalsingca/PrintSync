<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $stock_in_id
 * @property int $inventory_id
 * @property int $staff_id
 * @property string $quantity_added
 * @property Carbon $date_added
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'stock_in', key: 'stock_in_id')]
#[Fillable(['inventory_id', 'staff_id', 'quantity_added', 'date_added'])]
class StockIn extends Model
{
    /**
     * Get the inventory item for this stock-in record.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id', 'inventory_id');
    }

    /**
     * Get the staff member who recorded this stock-in.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_added' => 'decimal:3',
            'date_added' => 'datetime',
        ];
    }
}
