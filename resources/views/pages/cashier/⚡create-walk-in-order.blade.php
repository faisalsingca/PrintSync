<?php

use App\Models\Order;
use App\OrderServiceLine;
use App\Services\OrderService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Walk-in Order')] class extends Component
{
    public string $customer_name = '';

    public mixed $service_line = '';

    public mixed $description = null;

    public mixed $quantity = 1;

    public string $successMessage = '';

    public function mount(): void
    {
        Gate::authorize('createWalkIn', Order::class);
    }

    public function createWalkInOrder(OrderService $orderService): void
    {
        Gate::authorize('createWalkIn', Order::class);

        $validated = $this->validate([
            'customer_name' => ['required', 'string', 'max:100'],
            'service_line' => ['required', Rule::enum(OrderServiceLine::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $serviceLine = OrderServiceLine::from($validated['service_line']);

        $order = $orderService->createWalkInOrder(trim($validated['customer_name']), [
            'order_type' => $serviceLine->label(),
            'service_line' => $serviceLine->value,
            'description' => $validated['description'] ?? null,
            'quantity' => (int) $validated['quantity'],
        ]);

        $this->successMessage = "Walk-in order #{$order->order_id} was created for {$validated['customer_name']}. Set the total and record the payment on the Manage Payments page.";
        $this->reset('customer_name', 'service_line', 'description');
        $this->quantity = 1;
    }
};
?>

<div class="cashier-walk-in-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Walk-in Order</h1>
            <p class="max-w-xl text-sm leading-6 text-[var(--ps-muted)]">
                For customers who order in person and have no account. The order starts as pending and goes to the staff who handle that service.
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
                <label for="customer_name" class="text-sm font-medium text-[var(--ps-text)]">Customer name</label>
                <input id="customer_name" type="text" wire:model="customer_name" class="ps-input" maxlength="100" required>
                @error('customer_name') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

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