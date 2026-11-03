<?php

use App\Models\Order;
use App\Models\OrderTakeover;
use App\Models\User;
use App\OrderStatus;
use App\Services\AuditLogService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Details')] class extends Component
{
    public Order $order;

    public string $statusMessage = '';

    public string $takeoverReason = '';

    public ?string $paymentStatus = null;

    public bool $paymentCleared = false;

    public function mount(Order $order, PaymentService $paymentService): void
    {
        Gate::authorize('view', $order);

        $this->order = $order->load([
            'customer',
            'assignments' => fn ($query) => $query->with('staff')->orderByDesc('assignment_id'),
            'takeovers' => fn ($query) => $query->with(['coveringStaff', 'assignment.staff'])->orderByDesc('takeover_id'),
        ]);
        $this->refreshPayment($paymentService);
    }

    public function checkPayment(PaymentService $paymentService): void
    {
        $this->order = Order::query()
            ->with([
                'customer',
                'assignments' => fn ($query) => $query->with('staff')->orderByDesc('assignment_id'),
                'takeovers' => fn ($query) => $query->with(['coveringStaff', 'assignment.staff'])->orderByDesc('takeover_id'),
            ])
            ->findOrFail($this->order->order_id);
        $this->refreshPayment($paymentService);
    }

    public function takeOver(OrderService $orderService, AuditLogService $auditLog): void
    {
        $order = Order::query()->findOrFail($this->order->order_id);
        Gate::authorize('takeOver', $order);
        $staff = auth()->user();
        abort_unless($staff instanceof User, 403);

        $validated = $this->validate([
            'takeoverReason' => ['required', 'string', 'max:500'],
        ], [
            'takeoverReason.required' => 'Explain why you are taking over this order.',
        ]);

        $takeover = $orderService->takeOverOrder($order, $staff, $validated['takeoverReason']);
        $auditLog->record(
            $staff,
            'staff.order_taken_over',
            "Took over order #{$order->order_id} from {$takeover->assignment->staff->name}: {$takeover->reason}",
            OrderTakeover::class,
            $takeover->takeover_id,
            "Order #{$order->order_id}",
            [
                'assigned_staff_id' => $takeover->assignment->staff_id,
                'covering_staff_id' => $takeover->covering_staff_id,
                'reason' => $takeover->reason,
            ],
        );

        $this->order = Order::query()
            ->with([
                'customer',
                'assignments' => fn ($query) => $query->with('staff')->orderByDesc('assignment_id'),
                'takeovers' => fn ($query) => $query->with(['coveringStaff', 'assignment.staff'])->orderByDesc('takeover_id'),
            ])
            ->findOrFail($order->order_id);
        $this->takeoverReason = '';
        $this->statusMessage = "You are now covering order #{$order->order_id}. The original assignee remains recorded.";
    }

    public function updateProductionStatus(string $status, OrderService $orderService, AuditLogService $auditLog): void
    {
        $order = Order::query()->findOrFail($this->order->order_id);
        Gate::authorize('updateProductionStatus', $order);

        Validator::make(
            ['status' => $status],
            ['status' => ['required', Rule::enum(OrderStatus::class)]],
        )->validate();

        $this->order = $orderService->updateAssignedOrderStatus(
            $order,
            auth()->user(),
            OrderStatus::from($status),
        )->load([
            'customer',
            'assignments' => fn ($query) => $query->with('staff')->orderByDesc('assignment_id'),
            'takeovers' => fn ($query) => $query->with(['coveringStaff', 'assignment.staff'])->orderByDesc('takeover_id'),
        ]);
        $auditLog->record(
            auth()->user(),
            'staff.order_status_updated',
            "Updated order #{$order->order_id} to ".OrderStatus::from($status)->label().'.',
            Order::class,
            $order->order_id,
            "Order #{$order->order_id}",
            ['status' => $status],
        );
        $this->statusMessage = 'Order status updated to '.OrderStatus::from($status)->label().'.';
        $this->refreshPayment(app(PaymentService::class));
    }

    private function refreshPayment(PaymentService $paymentService): void
    {
        $this->paymentStatus = $paymentService->getPaymentStatus($this->order);
        $this->paymentCleared = $paymentService->isReadyForProduction($this->order);
    }
};
?>

<div class="staff-order-details-page grid gap-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="grid gap-1">
            <p class="text-sm font-medium text-[var(--ps-muted)]">Order #{{ $order->order_id }}</p>
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Order Details</h1>
        </div>

        @if (auth()->user()->role === 'owner')
            <a href="{{ route('owner.monitoring') }}" wire:navigate class="ps-button-ghost w-fit">Back to monitoring</a>
            @elseif (auth()->user()->role === 'cashier')
                <a href="{{ route('cashier.routing') }}" wire:navigate class="ps-button-ghost w-fit">Back to order routing</a>
            @else
            <a href="{{ route('staff.orders') }}" wire:navigate class="ps-button-ghost w-fit">Back to assigned orders</a>
        @endif
    </header>

    @if ($statusMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $statusMessage }}
        </div>
    @endif

    <section class="ps-glass grid gap-6 p-5 sm:p-8" aria-labelledby="staff-order-heading">
        <div class="flex flex-col gap-3 border-b border-[var(--ps-border)] pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid gap-1">
                <h2 id="staff-order-heading" class="text-xl font-semibold capitalize text-[var(--ps-text)]">{{ $order->order_type }}</h2>
                <p class="text-sm text-[var(--ps-muted)]">Customer: {{ $order->customerDisplayName() }}</p>
            </div>
            <x-status-badge :tone="OrderStatus::toneFor($order->status)" :label="OrderStatus::labelFor($order->status)" />
        </div>

        @php
            $primaryAssignment = $order->takeovers->first()?->assignment ?? $order->assignments->first();
            $takeover = $order->takeovers->first();
        @endphp
        <section class="grid gap-2 border-b border-[var(--ps-border)] pb-5" aria-label="Order assignment">
            @if ($primaryAssignment)
                <p class="text-sm text-[var(--ps-muted)]">Assigned to <span class="font-medium text-[var(--ps-text)]">{{ $primaryAssignment->staff->name }}</span></p>
            @else
                <p class="text-sm text-[var(--ps-muted)]">No primary staff assignment recorded.</p>
            @endif
            @if ($takeover)
                <p class="text-sm text-[var(--ps-muted)]">
                    Covered by <span class="font-medium text-[var(--ps-text)]">{{ $takeover->coveringStaff->name }}</span>:
                    {{ $takeover->reason }}
                </p>
            @endif
        </section>

        @can('takeOver', $order)
            <section class="grid gap-3 border-b border-[var(--ps-border)] pb-5" aria-labelledby="order-takeover-heading">
                <div class="grid gap-1">
                    <h2 id="order-takeover-heading" class="font-semibold text-[var(--ps-text)]">Take over this order</h2>
                    <p class="text-sm text-[var(--ps-muted)]">The original assignee stays recorded. Explain why you are covering, for example if they are unavailable.</p>
                </div>
                <label class="grid gap-1 text-sm font-medium text-[var(--ps-text)]" for="takeover-reason">
                    Reason for takeover
                    <textarea id="takeover-reason" wire:model="takeoverReason" class="ps-input min-h-20" maxlength="500" required></textarea>
                </label>
                @error('takeoverReason') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                <button type="button" wire:click="takeOver" wire:loading.attr="disabled" class="ps-button-secondary w-fit">Take over order</button>
            </section>
        @endcan

        <dl class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-1">
                <dt class="text-sm text-[var(--ps-muted)]">Quantity</dt>
                <dd class="font-medium text-[var(--ps-text)]">{{ number_format($order->quantity) }} {{ str('copy')->plural($order->quantity) }}</dd>
            </div>
            <div class="grid gap-1">
                <dt class="text-sm text-[var(--ps-muted)]">Submitted</dt>
                <dd class="font-medium text-[var(--ps-text)]">{{ $order->order_date->format('M j, Y \a\t g:i A') }}</dd>
            </div>
            <div class="grid gap-1">
                <dt class="text-sm text-[var(--ps-muted)]">Payment</dt>
                <dd>
                    <x-status-badge
                :tone="match ($paymentStatus) { 'paid' => 'success', 'partial' => 'warning', 'unpaid' => 'warning', default => 'neutral' }"
                :label="match ($paymentStatus) { 'paid' => 'Paid', 'partial' => 'Partially paid', 'unpaid' => 'Unpaid', default => 'Waiting for total' }"
            />
                </dd>
            </div>
            <div class="grid gap-1 sm:col-span-2">
                <dt class="text-sm text-[var(--ps-muted)]">Description</dt>
                <dd class="whitespace-pre-line text-[var(--ps-text)]">{{ $order->description ?: 'No description provided.' }}</dd>
            </div>
            <div class="grid gap-1 sm:col-span-2">
                <dt class="text-sm text-[var(--ps-muted)]">Design file</dt>
                <dd class="text-[var(--ps-text)]">
                    @if ($order->design_file)
                        <span class="text-sm text-[var(--ps-muted)]">Shown below. Preview only.</span>
                    @else
                        No design file attached.
                    @endif
                </dd>
            </div>
        </dl>

        @can('updateProductionStatus', $order)
            <div class="grid gap-3 border-t border-[var(--ps-border)] pt-5">
                <div class="grid gap-1">
                    <h2 class="font-semibold text-[var(--ps-text)]">Production Status</h2>
                    <p class="text-sm text-[var(--ps-muted)]">Advance the order through its production workflow.</p>
                </div>

                @if (! $paymentCleared)
                    <div wire:poll.10s="checkPayment" class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-[var(--ps-text)]" role="alert">
                        <p class="font-semibold">Locked: awaiting payment</p>
                        <p class="mt-1 text-[var(--ps-muted)]">
                            {{ $paymentStatus === null
                                ? 'The cashier has not set the total or recorded a payment yet.'
                                : 'No payment has been recorded yet.' }}
                            Production can start once the cashier records at least a partial payment. This page updates by itself.
                        </p>
                    </div>
                @elseif (($nextStatus = OrderStatus::tryFrom($order->status)?->next()) !== null)
                    <button
                        type="button"
                        wire:click="updateProductionStatus('{{ $nextStatus->value }}')"
                        wire:loading.attr="disabled"
                        class="ps-button-primary w-fit"
                    >
                        Mark {{ $nextStatus->label() }}
                    </button>
                @else
                    <p class="text-sm text-[var(--ps-muted)]">This order has completed production.</p>
                @endif
                <flux:error name="status" />
            </div>
        @endcan
    </section>

    @if ($order->design_file)
        @php
            $designExtension = strtolower(pathinfo($order->design_file, PATHINFO_EXTENSION));
            $designUrl = route('orders.design-file', [$order, 'inline' => 1]);
        @endphp
        <section class="ps-glass grid gap-4 p-5 sm:p-8" aria-labelledby="order-design-preview-heading">
            <div class="grid gap-1">
                <h2 id="order-design-preview-heading" class="text-xl font-semibold text-[var(--ps-text)]">Customer design</h2>
                <p class="text-sm text-[var(--ps-muted)]">Shown here so you can work from it. It is a preview only and cannot be downloaded from this page.</p>
            </div>
            <div class="overflow-hidden rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)]" oncontextmenu="return false">
                @if ($designExtension === 'pdf')
                    <iframe src="{{ $designUrl }}#toolbar=0&navpanes=0&scrollbar=1" title="Customer design file" class="h-[75vh] w-full border-0"></iframe>
                @else
                    <img src="{{ $designUrl }}" alt="Customer design file for order #{{ $order->order_id }}" class="mx-auto max-h-[80vh] w-auto max-w-full select-none object-contain" draggable="false">
                @endif
            </div>
        </section>
    @endif
</div>