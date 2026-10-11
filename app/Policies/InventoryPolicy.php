<?php

namespace App\Policies;

use App\Models\User;

class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return match ($user->role) {
            'staff' => $user->canReportLowStock(),
            'cashier', 'owner' => true,
            default => false,
        };
    }

    public function manage(User $user): bool
    {
        return $user->role === 'cashier';
    }

    public function addStock(User $user): bool
    {
        return $user->role === 'cashier';
    }

    public function createLowStockAlert(User $user): bool
    {
        return $user->canReportLowStock();
    }
}