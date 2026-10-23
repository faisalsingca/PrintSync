<?php

use App\Http\Requests\StorePaymentRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderTotalService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cashier Payments')] class extends Component
{
    public Collection $orders;

    public int|string $order_id = '';

    public ?Order $selectedOrder = null;

    public ?string $selectedOrderConfirmedTotal = null;

    public ?string $selectedOrderAmountPaid = null;

    public ?string $selectedOrderRemainingBalance = null;

    public ?string $selectedOrderFinancialStatus = null;

    // Set / raise the order total
    public string $confirmed_total = '';

    public string $total_reason = '';

    // Record a payment
    public string $payment_method = '';

    public string $received = '';

    public string $payment_date = '';

    public bool $received_in_person = false;

    // Payment records search + filter
    public string $search = '';

    public string $statusFilter = '';

    // 'manage' = Manage Payments page, 'records' = Payment Records page (same component, two routes)
    public string $view = 'manage';

    public function mount(): void
    {
        Gate::authorize('viewAny', Payment::class);

        $this->view = request()->routeIs('cashier.payment-records') ? 'records' : 'manage';

        // Payment Records links here with ?order=ID to record a payment for that order.
        if ($this->view === 'manage' && request()->filled('order')) {
            $this->order_id = (int) request()->query('order');
        }

        $this->refreshOrders();
        $this->payment_date = now()->format('Y-m-d\TH:i');
        $this->syncSelectedOrderSummary();
    }

    public function updatedOrderId(): void
    {
        $this->resetErrorBag();
        $this->syncSelectedOrderSummary();
    }

    public function selectOrder(int $orderId): void
    {
        $this->order_id = $orderId;
        $this->resetErrorBag();
        $this->syncSelectedOrderSummary();
    }

    /**
     * Every order that has a total, with what was paid, what is left, and its payment status.
     */
    #[Computed]
    public function allRows(): \Illuminate\Support\Collection
    {
        return Order::query()
            ->whereNotNull('confirmed_total')
            ->with(['customer', 'payments.cashier'])
            ->latest('order_date')
            ->latest('order_id')
            ->get()
            ->map(function (Order $order): array {
                $totalCents = (int) round(((float) $order->confirmed_total) * 100);
                $paidCents = (int) $order->payments->sum(
                    fn ($payment): int => (int) round(((float) $payment->received) * 100)
                );
                $balanceCents = max($totalCents - $paidCents, 0);

                $status = match (true) {
                    $paidCents === 0 => PaymentService::STATUS_UNPAID,
                    $paidCents >= $totalCents => PaymentService::STATUS_PAID,
                    default => PaymentService::STATUS_PARTIAL,
                };

                return [
                    'order' => $order,
                    'total' => '₱'.number_format($totalCents / 100, 2),
                    'paid' => '₱'.number_format($paidCents / 100, 2),
                    'balance' => '₱'.number_format($balanceCents / 100, 2),
                    'status' => $status,
                ];
            });
    }

    #[Computed]
    public function records(): \Illuminate\Support\Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $this->allRows
            ->when($this->statusFilter !== '', fn ($rows) => $rows->where('status', $this->statusFilter))
            ->when($search !== '', fn ($rows) => $rows->filter(function (array $row) use ($search): bool {
                $order = $row['order'];
                $haystack = '#'.$order->order_id.' '
                    .$order->customer->name.' '
                    .$order->order_type.' '
                    .$order->payments->map(fn ($payment) => $payment->cashier?->name)->filter()->implode(' ');

                return str_contains(mb_strtolower($haystack), $search);
            }))
            ->values();
    }

    #[Computed]
    public function statusCounts(): array
    {
        return [
            'all' => $this->allRows->count(),
            'unpaid' => $this->allRows->where('status', PaymentService::STATUS_UNPAID)->count(),
            'partial' => $this->allRows->where('status', PaymentService::STATUS_PARTIAL)->count(),
            'paid' => $this->allRows->where('status', PaymentService::STATUS_PAID)->count(),
        ];
    }

    public function saveTotal(OrderTotalService $orderTotalService): void
    {
        $validated = $this->validate([
            'order_id' => ['required', 'exists:orders,order_id'],
            'confirmed_total' => ['required', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,2'],
            'total_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::query()->findOrFail($validated['order_id']);
        $reason = trim((string) ($validated['total_reason'] ?? ''));

        $orderTotalService->confirmTotal(
            $order,
            auth()->user(),
            (string) $validated['confirmed_total'],
            $reason === '' ? null : $reason,
        );

        session()->flash('status', 'Order total saved.');
        $this->reset('confirmed_total', 'total_reason');
        $this->refreshOrders();
        $this->syncSelectedOrderSummary();
    }

    public function recordPayment(PaymentService $paymentService): void
    {
        Gate::authorize('create', Payment::class);

        $rules = Arr::except((new StorePaymentRequest)->rules(), ['cashier_id']);
        $rules['received_in_person'] = ['accepted'];
        $validated = $this->validate($rules);
        $order = Order::query()->findOrFail($validated['order_id']);

        if ($order->confirmed_total === null) {
            throw ValidationException::withMessages([
                'order_id' => 'Confirm the order total before recording a payment.',
            ]);
        }

        $paymentService->recordPayment(
            $order,
            auth()->user(),
            $validated['payment_method'],
            (string) $validated['received'],
            Carbon::parse($validated['payment_date']),
        );

        session()->flash('status', 'Payment recorded successfully.');
        $this->reset('payment_method', 'received', 'received_in_person');
        $this->payment_date = now()->format('Y-m-d\TH:i');
        $this->syncSelectedOrderSummary();
    }

    private function refreshOrders(): void
    {
        $this->orders = Order::query()
            ->with('customer')
            ->latest('order_date')
            ->get();
    }

    private function syncSelectedOrderSummary(): void
    {
        $this->selectedOrder = null;
        $this->selectedOrderConfirmedTotal = null;
        $this->selectedOrderAmountPaid = null;
        $this->selectedOrderRemainingBalance = null;
        $this->selectedOrderFinancialStatus = null;

        if ($this->order_id === '' || $this->order_id === null) {
            return;
        }

        $selectedOrder = Order::query()->with('payments')->find($this->order_id);

        if ($selectedOrder === null) {
            return;
        }

        $this->selectedOrder = $selectedOrder;

        if ($selectedOrder->confirmed_total === null) {
            return;
        }

        $paymentService = app(PaymentService::class);
        $this->selectedOrderConfirmedTotal = $selectedOrder->confirmed_total;
        $this->selectedOrderAmountPaid = $paymentService->getAmountPaid($selectedOrder);
        $this->selectedOrderRemainingBalance = $paymentService->getRemainingBalance($selectedOrder);
        $this->selectedOrderFinancialStatus = (string) str($paymentService->getPaymentStatus($selectedOrder))->replace('_', ' ')->title();
    }
};
?>

<div class="cashier-payments-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">{{ $view === 'records' ? 'Payment Records' : 'Manage Payments' }}</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">
            @if ($view === 'records')
                Every order that has a total, with what was paid and what is still owed.
            @else
                Set each order's total and record what the customer pays, in full or in parts.
            @endif
        </p>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ session('status') }}
        </div>
    @endif

    @if ($view === 'manage')
    <section class="ps-glass grid gap-6 p-5 sm:p-8" aria-labelledby="record-payment-heading">
        <div class="grid gap-1">
            <h2 id="record-payment-heading" class="text-xl font-semibold text-[var(--ps-text)]">Set Total &amp; Record Payment</h2>
            <p class="text-sm text-[var(--ps-muted)]">Step 1: choose an order. Step 2: set its total. Step 3: record the money received in person.</p>
        </div>

        @php
            $canSubmitPayment = $selectedOrder !== null
                && $selectedOrder->confirmed_total !== null
                && $selectedOrderRemainingBalance !== null
                && $selectedOrderRemainingBalance !== '0.00';
        @endphp

        @if ($orders->isEmpty())
            <p class="rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] px-4 py-3 text-sm text-[var(--ps-muted)]">
                There are no orders to record a payment for.
            </p>
        @else
            <flux:field>
                <flux:label for="order_id">Order</flux:label>
                <select id="order_id" wire:model.live="order_id" class="ps-input" required>
                    <option value="">Select an order</option>
                    @foreach ($orders as $order)
                        <option wire:key="payment-order-option-{{ $order->order_id }}" value="{{ $order->order_id }}">
                            #{{ $order->order_id }} — {{ $order->order_type }} — {{ $order->customer->name }}
                            @if ($order->confirmed_total !== null)
                                (Total: {{ $order->confirmed_total }})
                            @else
                                (Total not set)
                            @endif
                        </option>
                    @endforeach
                </select>
                <flux:error name="order_id" />
            </flux:field>

            @if ($selectedOrder !== null && $selectedOrder->confirmed_total !== null)
                <div class="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-4">
                    <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                        <p class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Order total</p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--ps-text)] tabular-nums">{{ $selectedOrderConfirmedTotal }}</p>
                    </div>
                    <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                        <p class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Amount paid</p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--ps-text)] tabular-nums">{{ $selectedOrderAmountPaid ?? '0.00' }}</p>
                    </div>
                    <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                        <p class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Balance left</p>
                        <p class="mt-2 text-2xl font-semibold text-[var(--ps-text)] tabular-nums">{{ $selectedOrderRemainingBalance ?? '0.00' }}</p>
                    </div>
                    <div class="rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                        <p class="text-xs font-medium uppercase tracking-[0.12em] text-[var(--ps-muted)]">Payment status</p>
                        <div class="mt-2">
                            @php $financial = strtolower($selectedOrderFinancialStatus ?? 'unpaid'); @endphp
                            <x-status-badge
                                :tone="match ($financial) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }"
                                :label="match ($financial) { 'paid' => 'Paid', 'partial' => 'Partially paid', default => 'Unpaid' }"
                            />
                        </div>
                    </div>
                </div>
            @endif

            {{-- Set or raise the total --}}
            @if ($selectedOrder !== null)
                <form wire:submit="saveTotal" class="grid gap-4 rounded-2xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4 sm:p-5 md:grid-cols-[1fr_1.5fr_auto] md:items-start">
                    <flux:field>
                        <flux:label for="confirmed_total">
                            {{ $selectedOrder->confirmed_total === null ? 'Order total (₱)' : 'Raise total to (₱)' }}
                        </flux:label>
                        <flux:input id="confirmed_total" wire:model="confirmed_total" type="number" min="0" step="0.01" placeholder="e.g. 2345" required />
                        <flux:error name="confirmed_total" />
                    </flux:field>
                    <flux:field>
                        <flux:label for="total_reason">Reason (optional)</flux:label>
                        <flux:input id="total_reason" wire:model="total_reason" type="text" maxlength="255" placeholder="e.g. bigger size, extra copies" />
                        <flux:error name="total_reason" />
                    </flux:field>
                    <div class="md:pt-6">
                        <button type="submit" class="ps-button-secondary w-full" wire:loading.attr="disabled" wire:target="saveTotal">
                            {{ $selectedOrder->confirmed_total === null ? 'Save total' : 'Update total' }}
                        </button>
                    </div>
                    @if ($selectedOrder->confirmed_total !== null)
                        <p class="text-xs leading-5 text-[var(--ps-muted)] md:col-span-3">A saved total can only be raised, never lowered. Every change is kept in the order's history.</p>
                    @endif
                </form>
            @endif

            <form wire:submit="recordPayment" class="grid gap-6">
                <div class="grid gap-6 md:grid-cols-2">
                    <flux:field>
                        <flux:label for="payment_method">Payment method</flux:label>
                        <select id="payment_method" wire:model="payment_method" class="ps-input" required>
                            <option value="">Select a method</option>
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                            <option value="bank_transfer">Bank transfer</option>
                        </select>
                        <flux:error name="payment_method" />
                    </flux:field>

                    <flux:field>
                        <flux:label for="received">Amount received now (₱)</flux:label>
                        <flux:input id="received" wire:model="received" type="number" min="0" step="0.01" inputmode="decimal" placeholder="e.g. 2000" required />
                        <flux:error name="received" />
                    </flux:field>

                    <flux:field>
                        <flux:label for="payment_date">Payment date and time</flux:label>
                        <flux:input id="payment_date" wire:model="payment_date" type="datetime-local" required />
                        <flux:error name="payment_date" />
                    </flux:field>

                    <div class="grid content-start gap-2">
                        <label for="received_in_person" class="flex min-h-10 items-center gap-3 text-sm text-[var(--ps-text)]">
                            <input id="received_in_person" type="checkbox" wire:model="received_in_person" class="size-4 accent-[var(--ps-accent)]">
                            I confirm this payment has been received in person.
                        </label>
                        <flux:error name="received_in_person" />
                    </div>
                </div>

                @if (! $canSubmitPayment)
                    <p class="rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] px-4 py-3 text-sm text-[var(--ps-muted)]">
                        @if ($selectedOrder === null)
                            Select an order above to record this payment.
                        @elseif ($selectedOrder->confirmed_total === null)
                            Set this order's total above first, then you can record the payment.
                        @else
                            This order has already been fully paid, so no additional payment can be recorded.
                        @endif
                    </p>
                @endif

                <div class="flex justify-end border-t border-[var(--ps-border)] pt-5">
                    <button type="submit" class="ps-button-primary" wire:loading.attr="disabled" wire:target="recordPayment" {{ $canSubmitPayment ? '' : 'disabled' }}>
                        <span wire:loading.remove wire:target="recordPayment">Record Payment</span>
                        <span wire:loading wire:target="recordPayment">Recording...</span>
                    </button>
                </div>
            </form>
        @endif
    </section>
    @endif

    @if ($view === 'records')
    <section class="ps-glass overflow-hidden" aria-labelledby="payment-records-heading">
        <header class="grid gap-4 border-b border-[var(--ps-border)] px-5 py-5 sm:px-6">
            <div class="grid gap-1">
                <h2 id="payment-records-heading" class="text-lg font-semibold tracking-tight text-[var(--ps-text)]">Payment Records</h2>
                <p class="text-sm text-[var(--ps-muted)]">
                    Every order that has a total. Search by customer, cashier, order number, or order type.
                    @php $needTotal = $orders->whereNull('confirmed_total')->count(); @endphp
                    @if ($needTotal > 0)
                        <span class="font-medium text-amber-500">{{ $needTotal }} {{ str('order')->plural($needTotal) }} still need a total.</span>
                    @endif
                </p>
            </div>

            <input type="search" wire:model.live.debounce.300ms="search" class="ps-input sm:max-w-md" placeholder="Search customer, cashier, or order #" aria-label="Search payment records">

            <div class="ps-settings-tabs">
                @foreach (['' => 'All', 'unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid'] as $value => $label)
                    <button
                        type="button"
                        wire:key="payment-filter-{{ $value === '' ? 'all' : $value }}"
                        wire:click="$set('statusFilter', '{{ $value }}')"
                        class="ps-settings-tab cursor-pointer"
                        @if ($statusFilter === $value) data-current @endif
                    >
                        {{ $label }} <span class="tabular-nums">({{ $this->statusCounts[$value === '' ? 'all' : $value] }})</span>
                    </button>
                @endforeach
            </div>
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($this->records as $row)
                @php $recordOrder = $row['order']; @endphp
                <li wire:key="payment-record-{{ $recordOrder->order_id }}" class="grid gap-3 px-5 py-4 sm:px-6">
                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1.5fr)_repeat(3,minmax(0,1fr))_auto] lg:items-center">
                        <div class="grid gap-1">
                            <h3 class="font-medium text-[var(--ps-text)]">
                                Order #{{ $recordOrder->order_id }}
                                <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $recordOrder->order_type }}</span>
                            </h3>
                            <p class="text-sm text-[var(--ps-muted)]">{{ $recordOrder->customer->name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--ps-muted)]">Total</p>
                            <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $row['total'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--ps-muted)]">Paid</p>
                            <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $row['paid'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--ps-muted)]">Balance</p>
                            <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $row['balance'] }}</p>
                        </div>
                        <x-status-badge
                            :tone="match ($row['status']) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }"
                            :label="match ($row['status']) { 'paid' => 'Paid', 'partial' => 'Partially paid', default => 'Unpaid' }"
                        />
                    </div>

                    @if ($recordOrder->payments->isNotEmpty())
                        <ul class="grid gap-1 rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] px-4 py-3 text-sm text-[var(--ps-muted)]">
                            @foreach ($recordOrder->payments->sortBy('payment_date') as $payment)
                                <li wire:key="payment-line-{{ $payment->payment_id }}" class="flex flex-wrap justify-between gap-2">
                                    <span>
                                        {{ str($payment->payment_method)->replace('_', ' ')->title()->replace('Gcash', 'GCash') }}
                                        · {{ $payment->payment_date->format('M j, Y g:i A') }}
                                        · by {{ $payment->cashier?->name ?? 'Unknown cashier' }}
                                    </span>
                                    <span class="font-medium tabular-nums text-[var(--ps-text)]">₱{{ number_format((float) $payment->received, 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($row['status'] !== 'paid')
                        <div>
                            <a href="{{ route('cashier.payments', ['order' => $recordOrder->order_id]) }}" wire:navigate class="ps-button-secondary">
                                Record payment for this order
                            </a>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">
                    {{ $search !== '' || $statusFilter !== '' ? 'No payment records match your search.' : 'No orders have a total yet. Set a total on the Manage Payments page to start tracking payments.' }}
                </li>
            @endforelse
        </ul>
    </section>
    @endif
</div>