<?php

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit Log')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', AuditLog::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'action', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'action', 'from', 'to');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        Gate::authorize('viewAny', AuditLog::class);

        return app(AuditLogService::class)->paginate(
            $this->search !== '' ? $this->search : null,
            $this->action !== '' ? $this->action : null,
            $this->from !== '' ? $this->from : null,
            $this->to !== '' ? $this->to : null,
        );
    }
};
?>

<div class="owner-audit-log-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Audit Log</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">
            A record of staff and administrator actions. Entries cannot be edited or deleted.
        </p>
    </header>

    <section class="ps-glass grid gap-4 p-5 sm:p-6" aria-label="Filter the audit log">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[2fr_1.4fr_1fr_1fr_auto] xl:items-end">
            <div class="grid gap-1">
                <label for="audit-search" class="text-sm font-medium text-[var(--ps-text)]">Search</label>
                <input id="audit-search" type="search" wire:model.live.debounce.400ms="search" class="ps-input" placeholder="Person or activity details">
            </div>
            <div class="grid gap-1">
                <label for="audit-action" class="text-sm font-medium text-[var(--ps-text)]">Action</label>
                <select id="audit-action" wire:model.live="action" class="ps-input">
                    <option value="">All actions</option>
                    @foreach (AuditLog::ACTIONS as $key => [$label])
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid gap-1">
                <label for="audit-from" class="text-sm font-medium text-[var(--ps-text)]">From</label>
                <input id="audit-from" type="date" wire:model.live="from" class="ps-input">
            </div>
            <div class="grid gap-1">
                <label for="audit-to" class="text-sm font-medium text-[var(--ps-text)]">To</label>
                <input id="audit-to" type="date" wire:model.live="to" class="ps-input">
            </div>
            <button type="button" wire:click="clearFilters" class="ps-button-ghost">Clear</button>
        </div>
    </section>

    <section class="ps-glass overflow-hidden" aria-label="Audit log entries">
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($this->logs as $log)
                <li wire:key="audit-log-{{ $log->id }}" class="grid gap-2 px-5 py-4 lg:grid-cols-[11rem_12rem_minmax(0,1fr)] lg:gap-6 sm:px-6">
                    <div class="text-sm text-[var(--ps-muted)]">
                        <p class="font-medium text-[var(--ps-text)]">{{ $log->created_at->format('M j, Y') }}</p>
                        <p>{{ $log->created_at->format('g:i:s A') }}</p>
                    </div>
                    <div class="grid content-start gap-1">
                        <p class="font-medium text-[var(--ps-text)]">{{ $log->actor_name }}</p>
                        @if ($log->actor_role)
                            <p class="text-xs capitalize text-[var(--ps-muted)]">{{ $log->actor_role }}</p>
                        @endif
                    </div>
                    <div class="grid gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-badge :tone="$log->actionTone()" :label="$log->actionLabel()" />
                            @if ($log->isBackfilled())
                                <x-status-badge tone="neutral" label="From earlier records" />
                            @endif
                            @if ($log->subject_label)
                                <span class="text-sm font-medium text-[var(--ps-text)]">{{ $log->subject_label }}</span>
                            @endif
                        </div>
                        <p class="text-sm leading-6 text-[var(--ps-muted)]">{{ $log->description }}</p>
                    </div>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">
                    No entries match. Actions are recorded here as they happen. To add earlier inventory changes, run: php artisan audit:backfill-inventory
                </li>
            @endforelse
        </ul>

        @if ($this->logs->hasPages())
            <footer class="flex items-center justify-between gap-3 border-t border-[var(--ps-border)] px-5 py-4 sm:px-6">
                <button type="button" wire:click="previousPage" @disabled($this->logs->onFirstPage()) class="ps-button-secondary">Newer</button>
                <p class="text-sm text-[var(--ps-muted)]">Page {{ $this->logs->currentPage() }} of {{ $this->logs->lastPage() }}</p>
                <button type="button" wire:click="nextPage" @disabled(! $this->logs->hasMorePages()) class="ps-button-secondary">Older</button>
            </footer>
        @endif
    </section>
</div>