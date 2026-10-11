@php
    $role = auth()->user()->role;

    $items = match ($role) {
        'customer' => [
            ['Dashboard', 'home', route('customer.dashboard'), request()->routeIs('customer.dashboard')],
            ['Create New Order', 'plus-circle', route('customer.orders.create'), request()->routeIs('customer.orders.create')],
            ['Recent Orders', 'clock', route('customer.dashboard').'#recent-orders-heading', false],
            ['All Orders', 'queue-list', route('customer.orders'), request()->routeIs('customer.orders', 'customer.orders.show')],
        ],
        'staff' => array_values(array_filter([
            ['Dashboard', 'home', route('staff.dashboard'), request()->routeIs('staff.dashboard')],
            ['View Assigned Orders', 'clipboard-document-list', route('staff.orders'), request()->routeIs('staff.orders', 'staff.orders.show')],
            auth()->user()->isInventoryClerk()
                ? ['Inventory', 'cube', route('staff.inventory'), request()->routeIs('staff.inventory')]
                : null,
        ])),
        'cashier' => [
            ['Dashboard', 'home', route('cashier.dashboard'), request()->routeIs('cashier.dashboard')],
            ['Manage Payments', 'banknotes', route('cashier.payments').'#record-payment-heading', false],
            ['Payment Records', 'document-text', route('cashier.payments').'#payment-records-heading', false],
        ],
        'owner' => [
            ['Dashboard', 'home', route('owner.dashboard'), request()->routeIs('owner.dashboard')],
            ['Orders by Current Status', 'chart-bar', route('owner.dashboard').'#owner-order-status-heading', false],
            ['Business Monitoring', 'presentation-chart-line', route('owner.monitoring'), request()->routeIs('owner.monitoring')],
            ['Order Routing', 'arrows-right-left', route('owner.monitoring').'#owner-routing-heading', false],
            ['Staff Service Specialties', 'users', route('owner.monitoring').'#owner-specialties-heading', false],
            ['Inventory Materials', 'cube', route('owner.monitoring').'#owner-inventory-heading', false],
            ['Low-Stock Alerts', 'exclamation-triangle', route('owner.monitoring').'#owner-low-stock-heading', false],
            ['Saved Payments', 'banknotes', route('owner.monitoring').'#owner-payments-heading', false],
        ],
        default => [],
    };
@endphp

<aside id="ps-rail" class="ps-rail" x-bind:data-open="railOpen ? '' : null" aria-label="Main navigation">
    <div class="ps-rail-brand">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-3">
            <span class="ps-brand-mark" aria-hidden="true">PS</span>
            <span>PrintSync</span>
        </a>
        <button type="button" class="ms-auto lg:hidden" x-on:click="railOpen = false" aria-label="Close navigation menu">
            <flux:icon.x-mark class="size-5" />
        </button>
    </div>

    <nav class="grid gap-1" aria-label="{{ ucfirst($role) }} modules">
        <div class="ps-rail-heading">{{ ucfirst($role) }} workspace</div>
        @foreach ($items as [$label, $icon, $href, $current])
            <a
                href="{{ $href }}"
                wire:navigate
                class="ps-nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium"
                @if ($current) data-current aria-current="page" @endif
                x-on:click="railOpen = false"
            >
                <x-dynamic-component :component="'flux::icon.'.$icon" class="size-5 shrink-0" />
                <span>{{ __($label) }}</span>
            </a>
        @endforeach
    </nav>
</aside>
