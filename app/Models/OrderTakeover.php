<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $takeover_id
 * @property int $order_id
 * @property int $assignment_id
 * @property int $covering_staff_id
 * @property string $reason
 * @property Carbon $taken_over_at
 */
#[Table(key: 'takeover_id')]
#[Fillable(['order_id', 'assignment_id', 'covering_staff_id', 'reason', 'taken_over_at'])]
class OrderTakeover extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OrderAssignment::class, 'assignment_id', 'assignment_id');
    }

    public function coveringStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'covering_staff_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_over_at' => 'datetime',
        ];
    }
}
