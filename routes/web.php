<?php

use App\Http\Controllers\Auth\LoginOtpController;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('orders/{order}/design-file', function (Request $request, Order $order) {
        Gate::authorize('viewDesignFile', $order);

        $path = $order->design_file;

        if (
            ! is_string($path)
            || preg_match('/\Aorder-designs\/[A-Za-z0-9_-]+\.(?:pdf|jpe?g|png)\z/i', $path) !== 1
        ) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        $isStaff = $request->user()->role === 'staff';

        // Staff only see the design as a preview on the order page. Opening the file link
        // directly (a page of its own, where "Save as" is offered) is refused for them.
        if ($isStaff && $request->header('Sec-Fetch-Dest') === 'document') {
            abort(403, 'Design files can only be previewed on the order page.');
        }

        if ($isStaff || $request->boolean('inline')) {
            return response()->file($disk->path($path), $headers + [
                'Content-Disposition' => "inline; filename=\"order-{$order->order_id}-design.{$extension}\"",
            ]);
        }

        return $disk->download(
            $path,
            "order-{$order->order_id}-design.{$extension}",
            $headers,
        );
    })->name('orders.design-file');

    Route::get('dashboard', function (Request $request) {
        return match ($request->user()->role) {
            'customer' => redirect()->route('customer.dashboard'),
            'staff' => redirect()->route('staff.dashboard'),
            'cashier' => redirect()->route('cashier.dashboard'),
            'owner' => redirect()->route('owner.dashboard'),
            'super_admin' => redirect()->route('super-admin.staff'),
            default => abort(403),
        };
    })->name('dashboard');

    Route::middleware(['role:customer', 'verified'])->group(function () {
        Route::livewire('customer/dashboard', 'pages::customer.dashboard')
            ->name('customer.dashboard');

        Route::livewire('customer/orders', 'pages::customer.orders')
            ->name('customer.orders');

        Route::livewire('customer/orders/create', 'pages::customer.create-order')
            ->name('customer.orders.create');

        Route::livewire('customer/orders/{order}', 'pages::customer.order-details')
            ->name('customer.orders.show');
    });

    Route::middleware('role:staff')->group(function () {
        Route::livewire('staff/dashboard', 'pages::staff.dashboard')
            ->name('staff.dashboard');

        Route::livewire('staff/orders', 'pages::staff.orders')
            ->name('staff.orders');

        Route::livewire('staff/orders/{order}', 'pages::staff.order-details')
            ->name('staff.orders.show');

        Route::livewire('staff/inventory', 'pages::staff.inventory')
            ->name('staff.inventory');
    });

    Route::middleware('role:cashier')->group(function () {
        Route::livewire('cashier/dashboard', 'pages::cashier.dashboard')
            ->name('cashier.dashboard');

        Route::livewire('cashier/walk-in-orders/create', 'pages::cashier.create-walk-in-order')
            ->name('cashier.walk-in.create');

        Route::livewire('cashier/payments', 'pages::cashier.payments')
            ->name('cashier.payments');

        Route::livewire('cashier/payment-records', 'pages::cashier.payments')
            ->name('cashier.payment-records');

        Route::livewire('cashier/inventory', 'pages::cashier.inventory')
            ->name('cashier.inventory');

        Route::livewire('cashier/inventory/create', 'pages::cashier.inventory-add')
            ->name('cashier.inventory.create');

        Route::livewire('cashier/inventory/archive', 'pages::cashier.inventory-archive')
            ->name('cashier.inventory.archive');

        Route::livewire('cashier/low-stock-reports', 'pages::cashier.low-stock-reports')
            ->name('cashier.low-stock-reports');
    });

    Route::middleware('role:owner')->group(function () {
        Route::livewire('owner/dashboard', 'pages::owner.dashboard')
            ->name('owner.dashboard');

        Route::livewire('owner/reports', 'pages::owner.reports')
            ->name('owner.reports');

        Route::livewire('owner/monitoring', 'pages::owner.monitoring')
            ->name('owner.monitoring');

        Route::livewire('owner/orders-status', 'pages::owner.orders-status')
            ->name('owner.orders-status');

        Route::livewire('owner/routing', 'pages::owner.routing')
            ->name('owner.routing');

        Route::livewire('owner/specialties', 'pages::owner.specialties')
            ->name('owner.specialties');

        Route::livewire('owner/inventory', 'pages::owner.inventory')
            ->name('owner.inventory');

        Route::livewire('owner/low-stock', 'pages::owner.low-stock')
            ->name('owner.low-stock');

        Route::livewire('owner/payments', 'pages::owner.payments')
            ->name('owner.payments');

        Route::livewire('owner/audit-logs', 'pages::owner.audit-logs')
            ->name('owner.audit-logs');

        Route::livewire('owner/orders/{order}', 'pages::staff.order-details')
            ->name('owner.orders.show');
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::livewire('super-admin/customers', 'pages::super-admin.customer-accounts')
            ->name('super-admin.customers');

        Route::livewire('super-admin/staff', 'pages::super-admin.staff-accounts')
            ->name('super-admin.staff');

        Route::livewire('super-admin/audit-logs', 'pages::owner.audit-logs')
            ->name('super-admin.audit-logs');
    });
});
Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('login/verify', [LoginOtpController::class, 'show'])->name('otp.show');
    Route::post('login/verify', [LoginOtpController::class, 'verify'])->name('otp.verify');
    Route::post('login/verify/resend', [LoginOtpController::class, 'resend'])->name('otp.resend');
});
require __DIR__.'/settings.php';
