<?php

use App\Models\Inventory;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Order;
use App\OrderStatus;
use App\Services\AuditLogService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Order Details')] class extends Component
{
    public Order $order;

    public string $statusMessage = '';

    public ?string $paymentStatus = null;

    public bool $paymentCleared = false;

    /** @var Collection<int, Inventory> */
    public Collection $inventoryList;

    /** @var Collection<int, InventoryAdjustmentRequest> */
    public Collection $orderAdjustmentRequests;

    /** @var array<int, string> Quantity inputs keyed by inventory_id. */
    public array $usageQty = [];

    /** @var array<int, string> Remarks inputs keyed by inventory_id. */
    public array $usageRemarks = [];

    public string $inventorySuccessMessage = '';

    public string $inventoryErrorMessage = '';

    public function mount(Order $order, PaymentService $paymentService, InventoryService $inventoryService): void
    {
        Gate::authorize('view', $order);

        $this->order = $order->load('customer');
        $this->refreshPayment($paymentService);
        $this->reloadInventory($inventoryService);
    }

    public function checkPayment(PaymentService $paymentService): void
    {
        $this->order = Order::query()->with('customer')->findOrFail($this->order->order_id);
        $this->refreshPayment($paymentService);
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
        )->load('customer');
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
        $this->reloadInventory(app(InventoryService::class));
    }

    /**
     * Submit a material usage report for this order.
     */
    public function reportUsage(int $inventoryId, InventoryService $inventoryService): void
    {
        Gate::authorize('createLowStockAlert', Inventory::class);

        $this->inventorySuccessMessage = '';
        $this->inventoryErrorMessage = '';

        $this->validate([
            "usageQty.$inventoryId" => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            "usageRemarks.$inventoryId" => ['nullable', 'string', 'max:500'],
        ], [], [
            "usageQty.$inventoryId" => 'quantity used',
        ]);

        $item = Inventory::query()->findOrFail($inventoryId);

        try {
            $inventoryService->createAdjustmentRequest(
                $item,
                auth()->user(),
                (string) $this->usageQty[$inventoryId],
                $this->usageRemarks[$inventoryId] ?? null,
                $this->order,
            );
        } catch (ValidationException $exception) {
            $this->inventoryErrorMessage = $exception->errors()['quantity'][0]
                ?? $exception->errors()['inventory'][0]
                ?? 'Could not submit usage report.';
            $this->reloadInventory($inventoryService);

            return;
        }

        $this->inventorySuccessMessage = "Usage of {$item->material_name} reported to the cashier.";
        unset($this->usageQty[$inventoryId], $this->usageRemarks[$inventoryId]);
        $this->reloadInventory($inventoryService);
    }

    /**
     * Whether the inventory usage section should be visible.
     */
    #[Computed]
    public function showInventorySection(): bool
    {
        $status = OrderStatus::tryFrom($this->order->status);

        return $status === OrderStatus::InProgress || $status === OrderStatus::ReadyForPickup;
    }

    private function refreshPayment(PaymentService $paymentService): void
    {
        $this->paymentStatus = $paymentService->getPaymentStatus($this->order);
        $this->paymentCleared = $paymentService->isReadyForProduction($this->order);
    }

    private function reloadInventory(InventoryService $inventoryService): void
    {
        $this->inventoryList = $inventoryService->getStaffInventoryList(auth()->user());
        $this->orderAdjustmentRequests = $inventoryService->getOrderAdjustmentRequests($this->order);
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

    @if ($this->showInventorySection)
        @php
            $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
        @endphp
        <section class="ps-glass grid gap-6 p-5 sm:p-8" aria-labelledby="inventory-usage-heading">
            <div class="grid gap-1">
                <h2 id="inventory-usage-heading" class="text-xl font-semibold text-[var(--ps-text)]">Material Usage</h2>
                <p class="text-sm text-[var(--ps-muted)]">Report how much of each material you used for this order. The cashier will apply the deduction.</p>
            </div>

            @if ($inventorySuccessMessage !== '')
                <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
                    {{ $inventorySuccessMessage }}
                </div>
            @endif

            @if ($inventoryErrorMessage !== '')
                <div role="alert" class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400">
                    {{ $inventoryErrorMessage }}
                </div>
            @endif

            {{-- Already-submitted reports for this order --}}
            @if ($orderAdjustmentRequests->isNotEmpty())
                <div class="grid gap-2">
                    <h3 class="text-sm font-semibold text-[var(--ps-text)]">Submitted reports for this order</h3>
                    <ul class="divide-y divide-[var(--ps-border)] overflow-hidden rounded-xl border border-[var(--ps-border)]">
                        @foreach ($orderAdjustmentRequests as $req)
                            <li wire:key="adj-req-{{ $req->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="grid gap-0.5">
                                    <p class="font-medium text-[var(--ps-text)]">{{ $req->inventory->material_name ?? '—' }}</p>
                                    <p class="text-sm text-[var(--ps-muted)]">
                                        {{ $fmt($req->quantity) }} {{ $req->inventory->unit ?? '' }}
                                        @if ($req->remarks)
                                            · {{ $req->remarks }}
                                        @endif
                                    </p>
                                </div>
                                <x-status-badge
                                    :tone="match ($req->status) {
                                        'completed' => 'success',
                                        'in_progress' => 'progress',
                                        default => 'warning',
                                    }"
                                    :label="match ($req->status) {
                                        'completed' => 'Applied',
                                        'in_progress' => 'Being reviewed',
                                        default => 'Pending cashier',
                                    }"
                                />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Per-material report forms --}}
            @if ($inventoryList->isEmpty())
                <p class="text-sm text-[var(--ps-muted)]">No materials are assigned to your specialties.</p>
            @else
                <ul class="divide-y divide-[var(--ps-border)] overflow-hidden rounded-xl border border-[var(--ps-border)]">
                    @foreach ($inventoryList as $item)
                        @php
                            $alreadyReported = $orderAdjustmentRequests->contains(
                                fn ($r) => $r->inventory_id === $item->inventory_id
                            );
                        @endphp
                        <li wire:key="inv-{{ $item->inventory_id }}" class="grid gap-3 px-4 py-4 sm:px-5">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="grid gap-0.5">
                                    <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                                    <p class="text-xs text-[var(--ps-muted)]">Available: {{ $fmt($item->quantity) }} {{ $item->unit }}</p>
                                </div>
                                <x-status-badge :tone="$item->stockStatusTone()" :label="$item->stockStatusLabel()" />
                            </div>

                            @if ($alreadyReported)
                                <p class="text-sm font-medium text-amber-500">Already reported for this order. Waiting for the cashier.</p>
                            @else
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.001"
                                        wire:model="usageQty.{{ $item->inventory_id }}"
                                        class="ps-input sm:max-w-40"
                                        placeholder="Qty used"
                                        aria-label="Quantity of {{ $item->material_name }} used for this order"
                                    >
                                    <input
                                        type="text"
                                        wire:model="usageRemarks.{{ $item->inventory_id }}"
                                        class="ps-input"
                                        maxlength="500"
                                        placeholder="Remarks (optional)"
                                        aria-label="Remarks for {{ $item->material_name }} usage"
                                    >
                                    <button
                                        type="button"
                                        wire:click="reportUsage({{ $item->inventory_id }})"
                                        wire:loading.attr="disabled"
                                        class="ps-button-secondary shrink-0"
                                    >Report usage</button>
                                </div>
                                @error("usageQty.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                                @error("usageRemarks.{$item->inventory_id}") <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>