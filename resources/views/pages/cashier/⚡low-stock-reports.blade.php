<?php

use App\Models\Inventory;
use App\Services\InventoryService;
use App\StaffSpecialtyType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Low-Stock Reports')] class extends Component
{
    public Collection $openAlerts;

    public function mount(): void
    {
        Gate::authorize('manage', Inventory::class);

        $this->openAlerts = app(InventoryService::class)->getOpenLowStockAlerts();
    }
};
?>

@php
    $fmt = fn ($number) => rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
@endphp

<div class="cashier-low-stock-page grid gap-8">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <h1 class="text-3xl font-semibold tracking-tight text-[var(--ps-text)]">Low-Stock Reports</h1>
            <p class="text-sm leading-6 text-[var(--ps-muted)]">Reports sent by staff. A report clears by itself once you add stock to that material.</p>
        </div>
        <a href="{{ route('cashier.inventory') }}" wire:navigate class="ps-button-primary w-fit">Go to inventory</a>
    </header>

    <section class="ps-glass overflow-hidden" aria-label="Open low-stock reports">
        <header class="flex items-center justify-between gap-3 border-b border-[var(--ps-border)] px-5 py-4 sm:px-6">
            <h2 class="text-lg font-semibold text-[var(--ps-text)]">Open reports</h2>
            <x-status-badge :tone="$openAlerts->isEmpty() ? 'success' : 'warning'" :label="$openAlerts->isEmpty() ? 'All handled' : $openAlerts->count().' need restocking'" />
        </header>

        <ul class="divide-y divide-[var(--ps-border)]">
            @forelse ($openAlerts as $alert)
                @php
                    $specialties = $alert->staff->specialties
                        ->map(fn ($specialty) => StaffSpecialtyType::tryFrom($specialty->specialty)?->label())
                        ->filter()
                        ->implode(', ');
                @endphp
                <li wire:key="open-alert-{{ $alert->alert_id }}" class="grid gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="grid gap-1">
                        <h3 class="font-medium text-[var(--ps-text)]">{{ $alert->inventory->material_name }}</h3>
                        <p class="text-sm text-[var(--ps-muted)]">
                            Reported by {{ $alert->staff->name }}@if ($specialties !== '') ({{ $specialties }})@endif
                            <span class="px-1" aria-hidden="true">·</span>
                            {{ $alert->alert_date->format('M j, Y g:i A') }}
                        </p>
                        <p class="text-sm text-[var(--ps-muted)]">
                            Now {{ $fmt($alert->inventory->quantity) }} {{ $alert->inventory->unit }}
                        </p>
                        @if ($alert->remarks)
                            <p class="text-sm text-[var(--ps-text)]">“{{ $alert->remarks }}”</p>
                        @endif
                    </div>
                    <x-status-badge :tone="$alert->inventory->stockStatusTone()" :label="$alert->inventory->stockStatusLabel()" />
                </li>
            @empty
                <li class="px-5 py-8 text-sm text-[var(--ps-muted)]">No open low-stock reports.</li>
            @endforelse
        </ul>
    </section>
</div>