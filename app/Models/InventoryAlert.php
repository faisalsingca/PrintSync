<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $alert_id
 * @property int $inventory_id
 * @property int $staff_id
 * @property string $alert_type
 * @property Carbon $alert_date
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(key: 'alert_id')]
#[Fillable(['inventory_id', 'staff_id', 'alert_type', 'alert_date', 'remarks'])]
class InventoryAlert extends Model
{
    /**
     * Get the inventory item associated with the alert.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id', 'inventory_id');
    }

    /**
     * Get the staff member who submitted the alert.
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
            'alert_date' => 'datetime',
        ];
    }
}
