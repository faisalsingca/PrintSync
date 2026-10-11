<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $order_total_history_id
 * @property int $order_id
 * @property string|null $previous_total
 * @property string $new_total
 * @property int|null $changed_by_id
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(key: 'order_total_history_id')]
#[Fillable(['order_id', 'previous_total', 'new_total', 'changed_by_id', 'reason'])]
class OrderTotalHistory extends Model
{
    /**
     * Get the order this total change belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Get the user who changed the order total.
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_total' => 'decimal:2',
            'new_total' => 'decimal:2',
        ];
    }
}
