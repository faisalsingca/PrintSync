<?php

use App\Models\AuditLog;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new #[Title('Staff Accounts')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $role = 'staff';

    public string $search = '';

    public string $statusMessage = '';

    public bool $statusMessageIsError = false;

    public bool $showCreateStaffModal = false;

    public bool $showStaffStatusConfirmationModal = false;

    public ?int $pendingStaffAccountId = null;

    public function mount(): void
    {
        Gate::authorize('manageStaffAccounts', User::class);
    }

    public function openCreateStaffModal(): void
    {
        Gate::authorize('manageStaffAccounts', User::class);

        $this->resetValidation();
        $this->reset('name', 'email');
        $this->role = 'staff';
        $this->statusMessage = '';
        $this->showCreateStaffModal = true;
    }

    public function createStaffAccount(): void
    {
        Gate::authorize('manageStaffAccounts', User::class);

        $this->email = mb_strtolower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('staff_invitations', 'email'),
            ],
            'role' => ['required', Rule::in(['staff', 'cashier'])],
        ]);

        $token = Str::random(64);
        $invitation = StaffInvitation::create([
            'name' => trim($validated['name']),
            'email' => $validated['email'],
            'role' => $validated['role'],
            'created_by' => auth()->id(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        $this->statusMessageIsError = ! $this->sendStaffInvitation($invitation, $token);
        $this->statusMessage = $this->statusMessageIsError
            ? "The invitation for {$invitation->email} is saved, but its email could not be sent. Resend it from the pending invitations list."
            : "Invitation sent to {$invitation->email}. The account will be created after they confirm and choose a password.";

        $this->reset('name', 'email');
        $this->role = 'staff';
        $this->showCreateStaffModal = false;
    }

    public function resendStaffInvitation(int $invitationId): void
    {
        Gate::authorize('manageStaffAccounts', User::class);

        $invitation = StaffInvitation::query()->findOrFail($invitationId);
        $token = Str::random(64);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'email_sent_at' => null,
        ])->save();

        $this->statusMessageIsError = ! $this->sendStaffInvitation($invitation, $token);
        $this->statusMessage = $this->statusMessageIsError
            ? "The invitation email could not be sent to {$invitation->email}. Check the mail configuration and try again."
            : "A new invitation was sent to {$invitation->email}.";
    }

    public function requestStaffAccountStatusChange(int $userId): void
    {
        Gate::authorize('manageStaffAccounts', User::class);

        User::query()
            ->whereIn('role', ['staff', 'cashier'])
            ->findOrFail($userId);

        $this->pendingStaffAccountId = $userId;
        $this->showStaffStatusConfirmationModal = true;
    }

    public function confirmStaffAccountStatusChange(AuditLogService $auditLog): void
    {
        Gate::authorize('manageStaffAccounts', User::class);

        abort_if($this->pendingStaffAccountId === null, 404);

        $user = User::query()
            ->whereIn('role', ['staff', 'cashier'])
            ->findOrFail($this->pendingStaffAccountId);

        $user->is_active = ! $user->is_active;
        $user->save();

        $action = $user->is_active ? 'admin.staff_activated' : 'admin.staff_deactivated';
        $actionText = $user->is_active ? 'activated' : 'deactivated';

        $auditLog->record(
            auth()->user(),
            $action,
            "{$user->name}'s account was {$actionText}.",
            User::class,
            $user->id,
            $user->name,
            ['is_active' => $user->is_active],
        );

        $this->statusMessage = "{$user->name}'s account was {$actionText}.";
        $this->statusMessageIsError = false;
        $this->pendingStaffAccountId = null;
        $this->showStaffStatusConfirmationModal = false;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function staffAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        Gate::authorize('manageStaffAccounts', User::class);

        return User::query()
            ->whereIn('role', ['staff', 'cashier'])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search));
            })
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, StaffInvitation>
     */
    public function pendingStaffInvitations(): \Illuminate\Database\Eloquent\Collection
    {
        Gate::authorize('manageStaffAccounts', User::class);

        return StaffInvitation::query()
            ->latest('created_at')
            ->get();
    }

    private function sendStaffInvitation(StaffInvitation $invitation, string $token): bool
    {
        $acceptUrl = URL::temporarySignedRoute(
            'staff-invitations.show',
            $invitation->expires_at,
            ['token' => $token],
        );

        try {
            Notification::route('mail', $invitation->email)->notify(
                new StaffAccountInvitation($invitation->name, $invitation->role, $acceptUrl),
            );
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return false;
        }

        $invitation->forceFill(['email_sent_at' => now()])->save();

        return true;
    }
};
?>

<div class="super-admin-staff-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Staff Accounts</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">Create employee accounts and control whether they can sign in. Existing orders and activity records remain when an account is deactivated.</p>
    </header>

    @if ($statusMessage !== '')
        <div role="status" class="rounded-lg border px-4 py-3 text-sm text-[var(--ps-text)] {{ $statusMessageIsError ? 'border-red-500/40 bg-red-500/10' : 'border-emerald-500/40 bg-emerald-500/10' }}">{{ $statusMessage }}</div>
    @endif

    <section class="grid gap-4" aria-label="Employee accounts">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="grid gap-3 sm:flex sm:items-center sm:justify-between sm:flex-1">
                <h2 class="text-xl font-semibold text-[var(--ps-text)]">Employee accounts</h2>
                <button type="button" wire:click="openCreateStaffModal" class="ps-button-primary">Create staff account</button>
            </div>
            <div class="grid gap-1 sm:w-80">
                <label for="staff-search" class="text-sm font-medium text-[var(--ps-text)]">Search</label>
                <input id="staff-search" type="search" wire:model.live.debounce.300ms="search" class="ps-input" placeholder="Name or email">
            </div>
        </div>

        <div class="ps-glass overflow-x-auto">
            <table class="w-full min-w-[38rem] text-left text-sm">
                <thead class="border-b border-[var(--ps-border)] text-[var(--ps-muted)]">
                    <tr><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 font-medium">Email</th><th class="px-4 py-3 font-medium">Role</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"><span class="sr-only">Account action</span></th></tr>
                </thead>
                <tbody class="divide-y divide-[var(--ps-border)]">
                    @forelse ($this->staffAccounts() as $staffAccount)
                        <tr wire:key="staff-account-{{ $staffAccount->id }}">
                            <td class="px-4 py-3 font-medium text-[var(--ps-text)]">{{ $staffAccount->name }}</td>
                            <td class="px-4 py-3 text-[var(--ps-muted)]">{{ $staffAccount->email }}</td>
                            <td class="px-4 py-3 capitalize text-[var(--ps-text)]">{{ $staffAccount->role }}</td>
                            <td class="px-4 py-3"><x-status-badge :tone="$staffAccount->is_active ? 'success' : 'danger'" :label="$staffAccount->is_active ? 'Active' : 'Deactivated'" /></td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="requestStaffAccountStatusChange({{ $staffAccount->id }})" class="ps-button-secondary">
                                    {{ $staffAccount->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-[var(--ps-muted)]">No employee accounts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid gap-3">
            <div class="grid gap-1">
                <h2 class="text-xl font-semibold text-[var(--ps-text)]">Pending invitations</h2>
                <p class="text-sm text-[var(--ps-muted)]">An employee account is added only after the recipient confirms the invitation and sets a password.</p>
            </div>

            <div class="ps-glass overflow-x-auto">
                <table class="w-full min-w-[38rem] text-left text-sm">
                    <thead class="border-b border-[var(--ps-border)] text-[var(--ps-muted)]">
                        <tr><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 font-medium">Email</th><th class="px-4 py-3 font-medium">Role</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"><span class="sr-only">Invitation action</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ps-border)]">
                        @forelse ($this->pendingStaffInvitations() as $staffInvitation)
                            <tr wire:key="staff-invitation-{{ $staffInvitation->id }}">
                                <td class="px-4 py-3 font-medium text-[var(--ps-text)]">{{ $staffInvitation->name }}</td>
                                <td class="px-4 py-3 text-[var(--ps-muted)]">{{ $staffInvitation->email }}</td>
                                <td class="px-4 py-3 capitalize text-[var(--ps-text)]">{{ $staffInvitation->role }}</td>
                                <td class="px-4 py-3">
                                    <x-status-badge
                                        :tone="$staffInvitation->email_sent_at === null || $staffInvitation->expires_at->isPast() ? 'warning' : 'info'"
                                        :label="$staffInvitation->email_sent_at === null ? 'Email not sent' : ($staffInvitation->expires_at->isPast() ? 'Expired' : 'Waiting for confirmation')"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" wire:click="resendStaffInvitation({{ $staffInvitation->id }})" wire:loading.attr="disabled" wire:target="resendStaffInvitation" class="ps-button-secondary">Resend invitation</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-[var(--ps-muted)]">No pending invitations.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <flux:modal wire:model.self="showCreateStaffModal" class="max-w-2xl">
        <form wire:submit="createStaffAccount" class="grid gap-5">
            <div class="grid gap-1">
                <flux:heading size="lg">Create employee account</flux:heading>
                <flux:text>Enter the employee's details and choose their account type. They will set their own password after confirming by email.</flux:text>
            </div>

            <div class="grid gap-1">
                <label for="staff-name" class="text-sm font-medium text-[var(--ps-text)]">Name</label>
                <input id="staff-name" type="text" wire:model="name" class="ps-input" maxlength="255" required>
                @error('name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-1">
                <label for="staff-email" class="text-sm font-medium text-[var(--ps-text)]">Email</label>
                <input id="staff-email" type="email" wire:model="email" class="ps-input" maxlength="255" required>
                @error('email') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-1">
                <label for="staff-role" class="text-sm font-medium text-[var(--ps-text)]">Account type</label>
                <select id="staff-role" wire:model="role" class="ps-input" required>
                    <option value="staff">Staff</option>
                    <option value="cashier">Cashier</option>
                </select>
                @error('role') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <button type="button" class="ps-button-secondary">Cancel</button>
                </flux:modal.close>
                <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="createStaffAccount">Create account</button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showStaffStatusConfirmationModal" class="max-w-lg">
        <div class="grid gap-5">
            <div class="grid gap-1">
                <flux:heading size="lg">Change staff account status?</flux:heading>
                <flux:text>This changes whether the selected employee can sign in.</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <button type="button" class="ps-button-secondary">Cancel</button>
                </flux:modal.close>
                <button type="button" wire:click="confirmStaffAccountStatusChange" wire:loading.attr="disabled" wire:target="confirmStaffAccountStatusChange" class="ps-button-primary">Confirm</button>
            </div>
        </div>
    </flux:modal>
</div>