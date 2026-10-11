<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $payment_id
 * @property int $order_id
 * @property int $cashier_id
 * @property string $payment_method
 * @property string $received
 * @property Carbon $payment_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(key: 'payment_id')]
#[Fillable(['order_id', 'cashier_id', 'payment_method', 'received', 'payment_date'])]
class Payment extends Model
{
    /**
     * Get the order for this payment.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Get the cashier who recorded this payment.
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received' => 'decimal:2',
            'payment_date' => 'datetime',
        ];
    }
}
