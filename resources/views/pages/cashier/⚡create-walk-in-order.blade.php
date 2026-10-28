<?php

use App\Models\Order;
use App\Models\User;
use App\OrderServiceLine;
use App\Services\OrderService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Walk-in Order')] class extends Component
{
    public string $customer_name = '';

    public string $customerSearch = '';

    public ?int $selectedCustomerId = null;

    public mixed $service_line = '';

    public mixed $description = null;

    public mixed $quantity = 1;

    public string $successMessage = '';

    public function mount(): void
    {
        Gate::authorize('createWalkIn', Order::class);
    }

    public function updatedCustomerSearch(): void
    {
        $this->selectedCustomerId = null;
    }

    public function selectCustomer(int $customerId): void
    {
        Gate::authorize('createWalkIn', Order::class);

        $customer = User::query()
            ->where('role', 'customer')
            ->findOrFail($customerId);

        $this->selectedCustomerId = $customer->id;
        $this->customerSearch = $customer->name;
    }

    public function clearCustomerSelection(): void
    {
        Gate::authorize('createWalkIn', Order::class);

        $this->selectedCustomerId = null;
        $this->customerSearch = '';
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function customerMatches(): Collection
    {
        $search = trim($this->customerSearch);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        return User::query()
            ->where('role', 'customer')
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email', 'phone']);
    }

    public function createWalkInOrder(OrderService $orderService): void
    {
        Gate::authorize('createWalkIn', Order::class);

        $validated = $this->validate([
            'customer_name' => [Rule::requiredIf($this->selectedCustomerId === null), 'nullable', 'string', 'max:100'],
            'selectedCustomerId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'customer')],
            'service_line' => ['required', Rule::enum(OrderServiceLine::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $serviceLine = OrderServiceLine::from($validated['service_line']);
        $customer = $this->selectedCustomerId === null
            ? null
            : User::query()->where('role', 'customer')->findOrFail($this->selectedCustomerId);

        $order = $orderService->createWalkInOrder(trim((string) ($validated['customer_name'] ?? '')), [
            'order_type' => $serviceLine->label(),
            'service_line' => $serviceLine->value,
            'description' => $validated['description'] ?? null,
            'quantity' => (int) $validated['quantity'],
        ], $customer);

        $this->successMessage = "Walk-in order #{$order->order_id} was created for {$order->customerDisplayName()}. Set the total and record the payment on the Manage Payments page.";
        $this->reset('customer_name', 'customerSearch', 'selectedCustomerId', 'service_line', 'description');
        $this->quantity = 1;
    }
};
?>

<div class="cashier-walk-in-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Walk-in Order</h1>
            <p class="max-w-xl text-sm leading-6 text-[var(--ps-muted)]">
                Search for a customer account first. If there is no match, create the order as a guest without creating an account.
            </p>
        </div>
        <a href="{{ route('cashier.payments') }}" wire:navigate class="ps-button-secondary w-fit">Go to payments</a>
    </header>

    @if ($successMessage !== '')
        <div role="status" class="flex items-start gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            <span aria-hidden="true" class="font-bold text-emerald-500">✓</span> {{ $successMessage }}
        </div>
    @endif

    <form wire:submit="createWalkInOrder" class="ps-glass grid gap-7 p-5 sm:p-8">
        <div class="grid gap-6 md:grid-cols-2">
            <div class="grid gap-1 md:col-span-2">
                <label for="customer-search" class="text-sm font-medium text-[var(--ps-text)]">Find an existing customer</label>
                <input id="customer-search" type="search" wire:model.live.debounce.300ms="customerSearch" class="ps-input" placeholder="Name, email, or phone">
                @if ($selectedCustomerId !== null)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-[var(--ps-border)] px-3 py-2 text-sm">
                        <span class="text-[var(--ps-text)]">Account selected: {{ $this->customerMatches->firstWhere('id', $selectedCustomerId)?->name ?? $customerSearch }}</span>
                        <button type="button" wire:click="clearCustomerSelection" class="ps-button-ghost">Clear</button>
                    </div>
                @endif
                @if ($selectedCustomerId === null && mb_strlen(trim($customerSearch)) >= 2)
                    <ul class="divide-y divide-[var(--ps-border)] rounded-lg border border-[var(--ps-border)]" aria-label="Matching customer accounts">
                        @forelse ($this->customerMatches as $customer)
                            <li wire:key="customer-match-{{ $customer->id }}">
                                <button type="button" wire:click="selectCustomer({{ $customer->id }})" class="grid w-full gap-1 px-3 py-2 text-left hover:bg-black/5">
                                    <span class="font-medium text-[var(--ps-text)]">{{ $customer->name }}</span>
                                    <span class="text-xs text-[var(--ps-muted)]">{{ $customer->email }}
                                        @if ($customer->phone !== null)
                                            · {{ $customer->phone }}
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @empty
                            <li class="px-3 py-2 text-sm text-[var(--ps-muted)]">No matching account found. Continue below as a guest.</li>
                        @endforelse
                    </ul>
                @endif
            </div>

            @if ($selectedCustomerId === null)
                <div class="grid gap-1 md:col-span-2">
                    <label for="customer_name" class="text-sm font-medium text-[var(--ps-text)]">Guest customer name</label>
                    <input id="customer_name" type="text" wire:model="customer_name" class="ps-input" maxlength="100" required>
                    @error('customer_name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="grid gap-1">
                <label for="service_line" class="text-sm font-medium text-[var(--ps-text)]">Order type</label>
                <select id="service_line" wire:model="service_line" class="ps-input" required>
                    <option value="">Select an order type</option>
                    @foreach (OrderServiceLine::cases() as $serviceLine)
                        <option value="{{ $serviceLine->value }}">{{ $serviceLine->label() }}</option>
                    @endforeach
                </select>
                @error('service_line') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-1">
                <label for="quantity" class="text-sm font-medium text-[var(--ps-text)]">Quantity (pieces)</label>
                <input id="quantity" type="number" min="1" step="1" wire:model="quantity" class="ps-input" required>
                @error('quantity') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-1 md:col-span-2">
                <label for="description" class="text-sm font-medium text-[var(--ps-text)]">Description <span class="font-normal text-[var(--ps-muted)]">(optional)</span></label>
                <textarea id="description" wire:model="description" rows="4" class="ps-input" placeholder="Sizes, paper, finishing, or other instructions."></textarea>
                @error('description') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end border-t border-[var(--ps-border)] pt-5">
            <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="createWalkInOrder">
                <span wire:loading.remove wire:target="createWalkInOrder">Create Order</span>
                <span wire:loading wire:target="createWalkInOrder">Saving...</span>
            </button>
        </div>
    </form>
</div>