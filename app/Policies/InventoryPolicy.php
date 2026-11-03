<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;

class InventoryPolicy
{
    /**
     * Staff see the materials they use, while the owner and cashier manage inventory.
     */
    public function viewAny(User $user): bool
    {
        return match ($user->role) {
            'staff' => $user->canReportMaterialUse(),
            'cashier', 'owner' => true,
            default => false,
        };
    }

    /**
     * Add, edit, archive, restore materials and deduct stock.
     */
    public function manage(User $user): bool
    {
        return in_array($user->role, ['cashier', 'owner'], true);
    }

    public function addStock(User $user): bool
    {
        return in_array($user->role, ['cashier', 'owner'], true);
    }

    public function createLowStockAlert(User $user): bool
    {
        return $user->canReportLowStock();
    }

    public function createAdjustmentRequest(User $user, Inventory $inventory): bool
    {
        if (! $user->canReportMaterialUse() || $inventory->isArchived()) {
            return false;
        }

        $specialties = $user->specialties()->pluck('specialty')->all();

        return $inventory->specialties()
            ->where(function ($query) use ($specialties): void {
                $query->where('specialty', 'all');

                if ($specialties !== []) {
                    $query->orWhereIn('specialty', $specialties);
                }
            })
            ->exists();
    }
}
