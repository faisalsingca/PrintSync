<?php

namespace App\Policies;

use App\Models\User;

class AuditLogPolicy
{
    /**
     * The owner monitors activity; the Super Admin manages staff and can review the audit trail.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['owner', 'super_admin'], true);
    }
}
