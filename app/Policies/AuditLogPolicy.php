<?php

namespace App\Policies;

use App\Models\User;

class AuditLogPolicy
{
    /**
     * Only the owner can read the audit log.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner';
    }
}
