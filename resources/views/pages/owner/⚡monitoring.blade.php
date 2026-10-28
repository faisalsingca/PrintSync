<?php

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Business Monitoring')] class extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Inventory::class);
        Gate::authorize('viewAny', Payment::class);
        Gate::authorize('manageStaffSpecialties', Order::class);
    }
};
?>

@php
    $modules = [
        ['Orders by Status', 'How many orders are in each stage.', route('owner.orders-status')],
        ['Order Routing', 'Which staff share each order.', route('owner.routing')],
        ['Staff Specialties', 'Set which services each staff member handles.', route('owner.specialties')],
        ['Inventory Materials', 'Current quantities and stock status.', route('owner.inventory')],
        ['Low-Stock Alerts', 'Reports from staff and automatic alerts.', route('owner.low-stock')],
        ['Saved Payments', 'Totals and recent payment records.', route('owner.payments')],
        ['Audit Log', 'Recorded actions by staff and administrators.', route('owner.audit-logs')],
        ['Reports', 'Printable sales, payment, inventory, and activity reports.', route('owner.reports')],
    ];
@endphp

<div class="owner-monitoring-page grid gap-8">
    <header class="grid gap-1">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Business Monitoring</h1>
        <p class="text-sm leading-6 text-[var(--ps-muted)]">Choose what you want to look at. Each one opens on its own page.</p>
    </header>

    <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($modules as [$title, $description, $href])
            <li wire:key="monitoring-module-{{ $loop->index }}">
                <a href="{{ $href }}" wire:navigate class="ps-glass grid h-full gap-2 p-5 transition-colors hover:border-[var(--ps-border-strong)] sm:p-6">
                    <h2 class="text-lg font-semibold text-[var(--ps-text)]">{{ $title }}</h2>
                    <p class="text-sm leading-6 text-[var(--ps-muted)]">{{ $description }}</p>
                    <span class="text-sm font-medium text-[var(--ps-accent)]">Open <span aria-hidden="true">→</span></span>
                </a>
            </li>
        @endforeach
    </ul>
</div>