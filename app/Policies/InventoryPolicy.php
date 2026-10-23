<?php

namespace App\Policies;

use App\Models\User;

class InventoryPolicy
{
    /**
     * Staff see the list so they can report low stock (graphic designers do not need to),
     * the cashier manages it, and the owner monitors it.
     */
    public function viewAny(User $user): bool
    {
        return match ($user->role) {
            'staff' => $user->canReportLowStock(),
            'cashier', 'owner' => true,
            default => false,
        };
    }

    /**
     * Add, edit, archive, restore materials and deduct stock. Inventory is handled by the cashier.
     */
    public function manage(User $user): bool
    {
        return $user->role === 'cashier';
    }

    public function addStock(User $user): bool
    {
        return $user->role === 'cashier';
    }

    /**
     * Report low stock to the cashier.
     */
    public function createLowStockAlert(User $user): bool
    {
        return $user->canReportLowStock();
    }
}
