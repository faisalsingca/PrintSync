<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $order_id
 * @property int $customer_id
 * @property Carbon $order_date
 * @property string $order_type
 * @property string|null $service_line
 * @property string|null $description
 * @property string|null $design_file
 * @property int $quantity
 * @property string $status
 * @property string|null $confirmed_total
 * @property Carbon|null $total_confirmed_at
 * @property int|null $total_confirmed_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(key: 'order_id')]
#[Fillable(['customer_id', 'order_date', 'order_type', 'service_line', 'description', 'design_file', 'quantity', 'status'])]
class Order extends Model
{
    /**
     * Get the customer who placed the order.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the staff assignments for the order.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class, 'order_id', 'order_id');
    }

    /**
     * Get the payments recorded for the order.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id', 'order_id');
    }

    /**
     * Get the user who confirmed the order total.
     */
    public function totalConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'total_confirmed_by_id');
    }

    /**
     * Get the total-price change history for the order.
     */
    public function totalHistories(): HasMany
    {
        return $this->hasMany(OrderTotalHistory::class, 'order_id', 'order_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'confirmed_total' => 'decimal:2',
            'total_confirmed_at' => 'datetime',
        ];
    }
}
