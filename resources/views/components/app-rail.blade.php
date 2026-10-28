@php
    $role = auth()->user()->role;
    $roleLabel = $role === 'super_admin' ? 'Super Admin' : ucfirst($role);

    $items = match ($role) {
        'customer' => [
            ['Dashboard', 'home', route('customer.dashboard'), request()->routeIs('customer.dashboard')],
            ['Create New Order', 'plus-circle', route('customer.orders.create'), request()->routeIs('customer.orders.create')],
            ['All Orders', 'queue-list', route('customer.orders'), request()->routeIs('customer.orders', 'customer.orders.show')],
        ],
        'staff' => array_values(array_filter([
            ['Dashboard', 'home', route('staff.dashboard'), request()->routeIs('staff.dashboard')],
            ['View Assigned Orders', 'clipboard-document-list', route('staff.orders'), request()->routeIs('staff.orders', 'staff.orders.show')],
            auth()->user()->canReportLowStock()
                ? ['Report Low Stock', 'exclamation-triangle', route('staff.inventory'), request()->routeIs('staff.inventory')]
                : null,
        ])),
        'cashier' => [
            ['Dashboard', 'home', route('cashier.dashboard'), request()->routeIs('cashier.dashboard')],
            ['Walk-in Order', 'plus-circle', route('cashier.walk-in.create'), request()->routeIs('cashier.walk-in.create')],
            ['Manage Payments', 'banknotes', route('cashier.payments'), request()->routeIs('cashier.payments')],
            ['Payment Records', 'document-text', route('cashier.payment-records'), request()->routeIs('cashier.payment-records')],
            ['Inventory', 'cube', route('cashier.inventory'), request()->routeIs('cashier.inventory')],
            ['Add Material', 'plus', route('cashier.inventory.create'), request()->routeIs('cashier.inventory.create')],
            ['Low-Stock Reports', 'exclamation-triangle', route('cashier.low-stock-reports'), request()->routeIs('cashier.low-stock-reports')],
            ['Archived Materials', 'archive-box', route('cashier.inventory.archive'), request()->routeIs('cashier.inventory.archive')],
        ],
        'owner' => [
            ['Dashboard', 'home', route('owner.dashboard'), request()->routeIs('owner.dashboard')],
            ['Reports', 'document-chart-bar', route('owner.reports'), request()->routeIs('owner.reports')],
            ['Business Monitoring', 'presentation-chart-line', route('owner.monitoring'), request()->routeIs('owner.monitoring')],
            ['Orders by Status', 'chart-bar', route('owner.orders-status'), request()->routeIs('owner.orders-status')],
            ['Order Routing', 'arrows-right-left', route('owner.routing'), request()->routeIs('owner.routing')],
            ['Staff Specialties', 'users', route('owner.specialties'), request()->routeIs('owner.specialties')],
            ['Inventory Materials', 'cube', route('owner.inventory'), request()->routeIs('owner.inventory')],
            ['Low-Stock Alerts', 'exclamation-triangle', route('owner.low-stock'), request()->routeIs('owner.low-stock')],
            ['Saved Payments', 'banknotes', route('owner.payments'), request()->routeIs('owner.payments')],
            ['Audit Log', 'shield-check', route('owner.audit-logs'), request()->routeIs('owner.audit-logs')],
        ],
        'super_admin' => [
            ['Customer Accounts', 'user-group', route('super-admin.customers'), request()->routeIs('super-admin.customers')],
            ['Staff Accounts', 'users', route('super-admin.staff'), request()->routeIs('super-admin.staff')],
            ['Audit Log', 'shield-check', route('super-admin.audit-logs'), request()->routeIs('super-admin.audit-logs')],
        ],
        default => [],
    };
@endphp

<aside id="ps-rail" class="ps-rail" x-bind:data-open="railOpen ? '' : null" aria-label="Main navigation">
    <div class="ps-rail-brand">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-3">
            <img src="{{ asset('images/rpg-prints-logo.png') }}" alt="RPG Prints" class="ps-brand-logo">
            <span>PrintSync</span>
        </a>
        <button type="button" class="ms-auto lg:hidden" x-on:click="railOpen = false" aria-label="Close navigation menu">
            <flux:icon.x-mark class="size-5" />
        </button>
    </div>

    <nav class="grid gap-1" aria-label="{{ $roleLabel }} modules">
        <div class="ps-rail-heading">{{ $roleLabel }} workspace</div>
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