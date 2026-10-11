<?php

namespace App\Policies;

use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user may view payment information.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['cashier', 'owner'], true);
    }

    /**
     * Determine whether the user may record a payment.
     */
    public function create(User $user): bool
    {
        return $user->role === 'cashier';
    }
}
