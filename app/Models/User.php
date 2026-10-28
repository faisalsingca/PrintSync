<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $role
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmailTrait, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the orders placed by the user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id', 'id');
    }

    /**
     * Get the order assignments assigned to the user.
     */
    public function orderAssignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class, 'staff_id', 'id');
    }

    /**
     * Get the specialties assigned to this staff user.
     */
    public function specialties(): HasMany
    {
        return $this->hasMany(StaffSpecialty::class, 'staff_id', 'id');
    }

    /**
     * Get the payments recorded by the user as cashier.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cashier_id', 'id');
    }

    /**
     * Get the order total changes made by the user.
     */
    public function orderTotalHistories(): HasMany
    {
        return $this->hasMany(OrderTotalHistory::class, 'changed_by_id', 'id');
    }

    /**
     * Get the stock-in records recorded by the user.
     */
    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'staff_id', 'id');
    }

    /**
     * Get the inventory alerts submitted by the user.
     */
    public function inventoryAlerts(): HasMany
    {
        return $this->hasMany(InventoryAlert::class, 'staff_id', 'id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Specialties that never need to report low stock (they only use a PC).
     */
    private const NO_STOCK_SPECIALTIES = ['graphic_designer'];

    /**
     * Whether this staff member can see the inventory page and report low stock.
     */
    public function canReportLowStock(): bool
    {
        if ($this->role !== 'staff') {
            return false;
        }

        $specialties = $this->specialties()->pluck('specialty');

        return $specialties->isEmpty()
            || $specialties->contains(fn (string $specialty): bool => ! in_array($specialty, self::NO_STOCK_SPECIALTIES, true));
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
