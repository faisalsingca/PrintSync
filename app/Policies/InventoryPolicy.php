<?php

namespace App\Policies;

use App\Models\User;

class InventoryPolicy
{
    /**
     * Determine whether the user may view the inventory list.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['staff', 'owner'], true);
    }

    /**
     * Determine whether the user may add, edit, or delete inventory materials.
     */
    public function manage(User $user): bool
    {
        return $user->isInventoryClerk();
    }

    /**
     * Determine whether the user may add stock.
     */
    public function addStock(User $user): bool
    {
        return $user->role === 'staff';
    }

    /**
     * Determine whether the user may create a low-stock alert.
     */
    public function createLowStockAlert(User $user): bool
    {
        return $user->role === 'staff';
    }
}
