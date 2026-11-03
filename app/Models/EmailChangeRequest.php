<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $old_email
 * @property string $new_email
 * @property string|null $old_token_hash
 * @property string $new_token_hash
 * @property Carbon|null $old_approved_at
 * @property Carbon|null $new_verified_at
 * @property Carbon $expires_at
 */
#[Fillable([
    'user_id',
    'old_email',
    'new_email',
    'old_token_hash',
    'new_token_hash',
    'old_approved_at',
    'new_verified_at',
    'expires_at',
])]
class EmailChangeRequest extends Model
{
    /**
     * Get the user requesting the email change.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_approved_at' => 'datetime',
            'new_verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
