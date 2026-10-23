<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditLogService
{
    /**
     * Write one audit line. The actor can be null for changes made by the system (seeders, console).
     *
     * @param  array<string, mixed>  $details
     */
    public function record(
        ?User $actor,
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        array $details = [],
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'System',
            'actor_role' => $actor?->role,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'description' => $description,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /**
     * Newest first, with optional filters.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(
        ?string $search = null,
        ?string $action = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return AuditLog::query()
            ->when($action, fn ($query) => $query->where('action', $action))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($search, function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('actor_name', 'like', $like)
                        ->orWhere('subject_label', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }
}
