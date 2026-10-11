<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\OrderStatus;
use App\StaffSpecialtyType;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Owner Monitoring')] class extends Component
{
    public array $orderStatusCounts = [];

    public Collection $inventory;

    public Collection $lowStockAlerts;

    public int $paymentCount = 0;

    public string $paymentTotal = '0.00';

    public Collection $recentPayments;

    public Collection $routingOverview;

    public int $unassignedCount = 0;

    public Collection $staffRoutingRoster;

    public array $staffSpecialtySelections = [];

    public string $successMessage = '';

    public function mount(
        OrderService $orderService,
        InventoryService $inventoryService,
        PaymentService $paymentService,
    ): void {
        Gate::authorize('viewAny', Inventory::class);
        Gate::authorize('viewAny', Payment::class);
        Gate::authorize('manageStaffSpecialties', Order::class);

        $this->orderStatusCounts = $orderService->getOrderStatusCounts();
        $this->inventory = $inventoryService->getInventoryList();
        $this->lowStockAlerts = $inventoryService->getLowStockAlerts();

        $paymentSummary = $paymentService->getPaymentSummary();
        $this->paymentCount = $paymentSummary['count'];
        $this->paymentTotal = $paymentSummary['received'];
        $this->recentPayments = $paymentService->getRecentPayments();
        $this->staffRoutingRoster = $orderService->getStaffRoutingRoster();

        foreach ($this->staffRoutingRoster as $staff) {
            $this->staffSpecialtySelections[$staff->id] = $staff->specialties
                ->pluck('specialty')
                ->all();
        }

        $this->refreshRouting($orderService);
    }

    public function updateStaffSpecialties(int $staffId, OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $specialtiesProperty = "staffSpecialtySelections.$staffId";
        $this->validate([
            $specialtiesProperty => ['array'],
            "$specialtiesProperty.*" => [Rule::enum(StaffSpecialtyType::class)],
        ]);

        $staff = User::query()
            ->where('role', 'staff')
            ->findOrFail($staffId);

        $orderService->updateStaffSpecialties(
            $staff,
            $this->staffSpecialtySelections[$staffId] ?? [],
        );

        $this->staffRoutingRoster = $orderService->getStaffRoutingRoster();
        $this->successMessage = "Specialties updated for {$staff->name}.";

        // Orders that were waiting for a matching staff member are assigned automatically.
        $assigned = $orderService->autoAssignUnassignedOrders();

        if ($assigned > 0) {
            $this->successMessage .= " {$assigned} waiting ".str('order')->plural($assigned).' assigned automatically.';
        }

        $this->refreshRouting($orderService);
    }

    public function assignWaitingOrders(OrderService $orderService): void
    {
        Gate::authorize('manageStaffSpecialties', Order::class);

        $assigned = $orderService->autoAssignUnassignedOrders();

        $this->successMessage = $assigned > 0
            ? "{$assigned} waiting ".str('order')->plural($assigned).' assigned automatically.'
            : 'No waiting orders could be assigned yet. Staff with a matching specialty are needed.';

        $this->staffRoutingRoster = $orderService->getStaffRoutingRoster();
        $this->refreshRouting($orderService);
    }

    private function refreshRouting(OrderService $orderService): void
    {
        $this->routingOverview = $orderService->getRoutingOverview();
        $this->unassignedCount = $orderService->countUnassignedOrders();
    }
};
?>

<div class="owner-monitoring-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Business Monitoring</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Read-only monitoring of orders, staff workload, inventory, and payments, plus staff service specialties.</p>
        </div>
        <a href="{{ route('owner.dashboard') }}" wire:navigate class="ps-button-secondary w-fit">
            <span aria-hidden="true">←</span> Owner dashboard
        </a>
    </header>

    @if ($successMessage !== '')
        <div role="status" class="rounded-xl border border-[var(--ps-border-strong)] bg-[var(--ps-accent-soft)] px-4 py-3 text-sm font-medium text-[var(--ps-text)]">
            {{ $successMessage }}
        </div>
    @endif

    <section class="ps-glass overflow-hidden" aria-labelledby="owner-routing-heading">
        <header class="flex flex-col gap-3 border-b border-[var(--ps-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="grid gap-1">
                <h2 id="owner-routing-heading" class="text-xl font-semibold text-[var(--ps-text)]">Order Routing</h2>
                <p class="text-sm text-[var(--ps-muted)]">Orders are assigned automatically by order type to staff who handle that service and share the workload. This view is read-only.</p>
            </div>
            @if ($unassignedCount > 0)
                <button type="button" wire:click="assignWaitingOrders" wire:loading.attr="disabled" class="ps-button-secondary w-fit">
                    Assign {{ $unassignedCount }} waiting {{ str('order')->plural($unassignedCount) }}
                </button>
            @endif
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($routingOverview as $order)
                @php $assignment = $order->assignments->first(); @endphp
                <li wire:key="owner-routing-order-{{ $order->order_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,14rem)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            <a href="{{ route('owner.orders.show', $order) }}" wire:navigate class="hover:text-[var(--ps-accent)]">
                                Order #{{ $order->order_id }} <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $order->order_type }}</span>
                            </a>
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">{{ $order->customer->name }} · Submitted {{ $order->order_date->format('M j, Y') }}</p>
                    </div>
                    <p class="text-sm">
                        @if ($assignment)
                            <span class="text-[var(--ps-muted)]">Handled by</span>
                            <span class="font-medium text-[var(--ps-text)]">{{ $assignment->staff->name }}</span>
                        @elseif ($order->service_line === null)
                            <span class="text-[var(--ps-muted)]">No order type set</span>
                        @else
                            <span class="font-medium text-amber-500">Waiting for eligible staff</span>
                        @endif
                    </p>
                    <span class="ps-status-badge w-fit">{{ OrderStatus::labelFor($order->status) }}</span>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No orders have been recorded.</li>
            @endforelse
        </ul>
    </section>

    <section class="ps-glass grid gap-5 p-5 sm:p-6" aria-labelledby="owner-specialties-heading">
        <div class="grid gap-1">
            <h2 id="owner-specialties-heading" class="text-xl font-semibold text-[var(--ps-text)]">Staff Service Specialties</h2>
            <p class="text-sm text-[var(--ps-muted)]">Orders are assigned automatically to staff whose specialty matches the order type. Machine operators are limited to their documented support lines.</p>
        </div>
        <ul class="grid gap-4 lg:grid-cols-2">
            @forelse ($staffRoutingRoster as $staff)
                <li wire:key="owner-staff-specialties-{{ $staff->id }}" class="grid gap-4 rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4 sm:p-5">
                    <div class="grid gap-1">
                        <h3 class="font-semibold text-[var(--ps-text)]">{{ $staff->name }}</h3>
                        <p class="text-sm text-[var(--ps-muted)]">{{ $staff->active_assignment_count }} active {{ str('task')->plural($staff->active_assignment_count) }}</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (StaffSpecialtyType::cases() as $specialty)
                            <label class="flex items-center gap-2 text-sm text-[var(--ps-text)]">
                                <flux:checkbox
                                    wire:model="staffSpecialtySelections.{{ $staff->id }}"
                                    value="{{ $specialty->value }}"
                                />
                                <span>{{ $specialty->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <flux:error name="staffSpecialtySelections.{{ $staff->id }}" />
                    <button
                        type="button"
                        wire:click="updateStaffSpecialties({{ $staff->id }})"
                        wire:loading.attr="disabled"
                        class="ps-button-secondary w-fit"
                    >
                        Save specialties
                    </button>
                </li>
            @empty
                <li class="rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] px-4 py-8 text-sm text-[var(--ps-muted)]">No staff accounts are available.</li>
            @endforelse
        </ul>
    </section>

    <section class="ps-glass grid gap-5 p-5 sm:p-6" aria-labelledby="owner-monitoring-orders-heading">
        <div class="grid gap-1">
            <h2 id="owner-monitoring-orders-heading" class="text-xl font-semibold text-[var(--ps-text)]">Orders by Current Status</h2>
            <p class="text-sm text-[var(--ps-muted)]">Status groups use the values currently saved on orders.</p>
        </div>
        @if ($orderStatusCounts === [])
            <p class="text-sm text-[var(--ps-muted)]">No orders have been recorded.</p>
        @else
            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($orderStatusCounts as $statusCount)
                    <div wire:key="owner-monitoring-status-{{ $statusCount['status'] }}" class="flex items-center justify-between gap-3 rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4">
                        <dt class="ps-status-badge">{{ OrderStatus::labelFor($statusCount['status']) }}</dt>
                        <dd class="text-xl font-semibold tabular-nums text-[var(--ps-text)]">{{ number_format($statusCount['count']) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="owner-inventory-heading">
        <header class="grid gap-1 border-b border-[var(--ps-border)] px-5 py-5 sm:px-6">
            <h2 id="owner-inventory-heading" class="text-xl font-semibold text-[var(--ps-text)]">Inventory Materials</h2>
            <p class="text-sm text-[var(--ps-muted)]">Current saved quantities, units, and inventory status.</p>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($inventory as $item)
                <li wire:key="owner-inventory-{{ $item->inventory_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center sm:px-6">
                    <h3 class="font-medium text-[var(--ps-text)]">{{ $item->material_name }}</h3>
                    <p class="text-sm tabular-nums text-[var(--ps-text)]">{{ $item->quantity }} {{ $item->unit }}</p>
                    <span class="ps-status-badge w-fit">{{ str($item->status)->replace('_', ' ')->title() }}</span>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No inventory materials have been recorded.</li>
            @endforelse
        </ul>
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="owner-low-stock-heading">
        <header class="grid gap-1 border-b border-[var(--ps-border)] px-5 py-5 sm:px-6">
            <h2 id="owner-low-stock-heading" class="text-xl font-semibold text-[var(--ps-text)]">Recorded Low-Stock Alerts</h2>
            <p class="text-sm text-[var(--ps-muted)]">Only alerts explicitly recorded by staff are listed; no quantity threshold is inferred.</p>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($lowStockAlerts as $alert)
                <li wire:key="owner-low-stock-alert-{{ $alert->alert_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">{{ $alert->inventory->material_name }} <span class="ps-status-badge">{{ str($alert->alert_type)->replace('_', ' ')->title() }}</span></h3>
                        <p class="text-sm text-[var(--ps-muted)]">
                            Current quantity: {{ $alert->inventory->quantity }} {{ $alert->inventory->unit }}
                            @if ($alert->remarks)
                                <span aria-hidden="true">·</span> {{ $alert->remarks }}
                            @endif
                        </p>
                    </div>
                    <time class="text-sm text-[var(--ps-muted)]" datetime="{{ $alert->alert_date->toIso8601String() }}">{{ $alert->alert_date->format('M j, Y g:i A') }}</time>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No low-stock alerts have been recorded.</li>
            @endforelse
        </ul>
    </section>

    <section class="ps-glass overflow-hidden" aria-labelledby="owner-payments-heading">
        <header class="grid gap-4 border-b border-[var(--ps-border)] px-5 py-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end sm:px-6">
            <div class="grid gap-1">
                <h2 id="owner-payments-heading" class="text-xl font-semibold text-[var(--ps-text)]">Saved Payments</h2>
                <p class="text-sm text-[var(--ps-muted)]">Totals and recent records from the payments currently stored.</p>
            </div>
            <dl class="grid gap-1 sm:text-right">
                <dt class="text-sm text-[var(--ps-muted)]">Total received ({{ number_format($paymentCount) }} {{ str('payment')->plural($paymentCount) }})</dt>
                <dd class="text-2xl font-semibold tabular-nums text-[var(--ps-text)]">{{ $paymentTotal }}</dd>
            </dl>
        </header>
        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($recentPayments as $payment)
                <li wire:key="owner-monitoring-payment-{{ $payment->payment_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">
                            Order #{{ $payment->order->order_id }}
                            <span class="font-normal capitalize text-[var(--ps-muted)]">— {{ $payment->order->order_type }}</span>
                        </h3>
                        <p class="text-sm text-[var(--ps-muted)]">
                            {{ str($payment->payment_method)->replace('_', ' ')->title()->replace('Gcash', 'GCash') }}
                            <span class="px-1" aria-hidden="true">·</span>
                            {{ $payment->payment_date->format('M j, Y g:i A') }}
                        </p>
                    </div>
                    <p class="font-semibold tabular-nums text-[var(--ps-text)]">{{ $payment->received }}</p>
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No payments have been recorded.</li>
            @endforelse
        </ul>
    </section>
</div>