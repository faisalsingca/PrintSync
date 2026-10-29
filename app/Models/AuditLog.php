<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line in the audit log: who did what, and when. Entries are never edited.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $actor_name
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property string $description
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 */
#[Fillable([
    'user_id', 'actor_name', 'actor_role', 'action',
    'subject_type', 'subject_id', 'subject_label', 'description', 'details',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Every action the log can hold: label shown on screen and the status tone for its badge.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ACTIONS = [
        'inventory.created' => ['Material added', 'success'],
        'inventory.updated' => ['Material edited', 'info'],
        'inventory.archived' => ['Material archived', 'warning'],
        'inventory.restored' => ['Material restored', 'success'],
        'inventory.stock_added' => ['Stock added', 'success'],
        'inventory.stock_deducted' => ['Stock deducted', 'danger'],
        'inventory.low_stock_reported' => ['Low stock reported', 'warning'],
        'admin.staff_created' => ['Staff account created', 'info'],
        'admin.staff_activated' => ['Staff account activated', 'success'],
        'admin.staff_deactivated' => ['Staff account deactivated', 'warning'],
        'admin.customer_activated' => ['Customer account activated', 'success'],
        'admin.customer_deactivated' => ['Customer account deactivated', 'warning'],
        'staff.assignment_accepted' => ['Order accepted', 'success'],
        'staff.assignment_declined' => ['Order declined', 'warning'],
        'staff.order_status_updated' => ['Production status updated', 'info'],
        'cashier.order_total_confirmed' => ['Order total confirmed', 'info'],
        'cashier.payment_recorded' => ['Payment recorded', 'success'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True for entries rebuilt from records that existed before the audit log was introduced.
     */
    public function isBackfilled(): bool
    {
        return (bool) ($this->details['backfilled'] ?? false);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action][0] ?? $this->action;
    }

    public function actionTone(): string
    {
        return self::ACTIONS[$this->action][1] ?? 'neutral';
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
