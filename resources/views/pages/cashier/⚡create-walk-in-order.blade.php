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

        $this->successMessage = "Walk-in order #{$order->order_id} was created for {$validated['customer_name']}. Set the total and record the payment on the Payments page.";
        $this->reset('customer_name', 'service_line', 'description');
        $this->quantity = 1;
    }
};
?>

<div class="cashier-walk-in-page grid gap-8">
    <section class="relative isolate overflow-hidden rounded-[1.6rem] border border-[var(--ps-border-strong)] bg-[radial-gradient(ellipse_at_82%_8%,rgb(184_243_74/13%),transparent_42%),var(--ps-glass)] px-6 py-8 shadow-[var(--ps-shadow)] sm:px-9 sm:py-10">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div class="grid gap-2">
                <span class="inline-flex w-fit rounded-full border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-3 py-1 text-xs font-semibold tracking-wide text-[var(--ps-accent)]">
                    CASHIER WORKSPACE
                </span>
                <h1 class="text-3xl font-semibold tracking-[-0.045em] text-[var(--ps-text)] sm:text-4xl">Walk-in Order</h1>
                <p class="max-w-xl text-sm leading-6 text-[var(--ps-muted)]">
                    For customers who order in person and have no account. The order starts as pending and goes to the team member who handles the service.
                </p>
            </div>
            <a href="{{ route('cashier.payments') }}" wire:navigate class="ps-button-ghost w-fit">Go to payments</a>
        </div>
    </section>

    @if ($successMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $successMessage }}
        </div>
    @endif

    <form wire:submit="createWalkInOrder" class="ps-glass grid gap-7 p-5 sm:p-8">
        <div class="grid gap-6 md:grid-cols-2">
            <flux:field class="md:col-span-2">
                <flux:label for="customer_name">Customer name</flux:label>
                <flux:input id="customer_name" wire:model="customer_name" type="text" maxlength="100" required />
                <flux:error name="customer_name" />
            </flux:field>

            <flux:field>
                <flux:label for="service_line">Order type</flux:label>
                <select id="service_line" wire:model="service_line" class="ps-input" required>
                    <option value="">Select an order type</option>
                    @foreach (OrderServiceLine::cases() as $serviceLine)
                        <option value="{{ $serviceLine->value }}">{{ $serviceLine->label() }}</option>
                    @endforeach
                </select>
                <flux:error name="service_line" />
            </flux:field>

            <flux:field>
                <flux:label for="quantity">Quantity (pieces)</flux:label>
                <flux:input id="quantity" wire:model="quantity" type="number" min="1" step="1" required />
                <flux:error name="quantity" />
            </flux:field>

            <flux:field class="md:col-span-2">
                <flux:label for="description">Description <span class="font-normal text-[var(--ps-muted)]">(optional)</span></flux:label>
                <flux:textarea id="description" wire:model="description" rows="4" placeholder="Sizes, paper, finishing, or other instructions." />
                <flux:error name="description" />
            </flux:field>
        </div>

        <div class="flex justify-end border-t border-[var(--ps-border)] pt-5">
            <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="createWalkInOrder">
                <span wire:loading.remove wire:target="createWalkInOrder">Create Order</span>
                <span wire:loading wire:target="createWalkInOrder">Saving...</span>
            </button>
        </div>
    </form>
</div>