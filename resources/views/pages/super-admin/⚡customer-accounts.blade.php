<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer Accounts')] class extends Component
{
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewCustomerAccounts', User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function customerAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        Gate::authorize('viewCustomerAccounts', User::class);

        return User::query()
            ->select(['id', 'name', 'email', 'phone', 'is_active', 'created_at'])
            ->where('role', 'customer')
            ->when(trim($this->search) !== '', function ($query): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
};
?>

<div class="super-admin-customer-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Customer Accounts</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">View registered customer contact details and account status.</p>
    </header>

    <section class="grid gap-4" aria-label="Customer accounts">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <h2 class="text-xl font-semibold text-[var(--ps-text)]">Registered customers</h2>
            <div class="grid gap-1 sm:w-80">
                <label for="customer-search" class="text-sm font-medium text-[var(--ps-text)]">Search</label>
                <input id="customer-search" type="search" wire:model.live.debounce.300ms="search" class="ps-input" placeholder="Name, email, or phone">
            </div>
        </div>

        <div class="ps-glass overflow-x-auto">
            <table class="w-full min-w-[38rem] text-left text-sm">
                <thead class="border-b border-[var(--ps-border)] text-[var(--ps-muted)]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Joined</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--ps-border)]">
                    @forelse ($this->customerAccounts() as $customer)
                        <tr wire:key="customer-account-{{ $customer->id }}">
                            <td class="px-4 py-3 font-medium text-[var(--ps-text)]">{{ $customer->name }}</td>
                            <td class="px-4 py-3 text-[var(--ps-muted)]">{{ $customer->email }}</td>
                            <td class="px-4 py-3 text-[var(--ps-muted)]">{{ $customer->phone ?: '—' }}</td>
                            <td class="px-4 py-3"><x-status-badge :tone="$customer->is_active ? 'success' : 'danger'" :label="$customer->is_active ? 'Active' : 'Deactivated'" /></td>
                            <td class="px-4 py-3 text-[var(--ps-muted)]">{{ $customer->created_at?->format('M j, Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-[var(--ps-muted)]">No customer accounts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
