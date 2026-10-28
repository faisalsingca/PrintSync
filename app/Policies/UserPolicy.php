<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function manageStaffAccounts(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    public function viewCustomerAccounts(User $user): bool
    {
        return $user->role === 'super_admin';
    }
}
