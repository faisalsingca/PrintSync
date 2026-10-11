<?php

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('orders/{order}/design-file', function (Order $order) {
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

        return $disk->download(
            $path,
            "order-{$order->order_id}-design.{$extension}",
            ['X-Content-Type-Options' => 'nosniff'],
        );
    })->name('orders.design-file');

    Route::get('dashboard', function (Request $request) {
        return match ($request->user()->role) {
            'customer' => redirect()->route('customer.dashboard'),
            'staff' => redirect()->route('staff.dashboard'),
            'cashier' => redirect()->route('cashier.dashboard'),
            'owner' => redirect()->route('owner.dashboard'),
            default => abort(403),
        };
    })->name('dashboard');

    Route::middleware('role:customer')->group(function () {
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

        Route::livewire('cashier/payments', 'pages::cashier.payments')
            ->name('cashier.payments');
    });

    Route::middleware('role:owner')->group(function () {
        Route::livewire('owner/dashboard', 'pages::owner.dashboard')
            ->name('owner.dashboard');

        Route::livewire('owner/monitoring', 'pages::owner.monitoring')
            ->name('owner.monitoring');

        Route::livewire('owner/orders/{order}', 'pages::staff.order-details')
            ->name('owner.orders.show');
    });
});
Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('login/verify', [\App\Http\Controllers\Auth\LoginOtpController::class, 'show'])->name('otp.show');
    Route::post('login/verify', [\App\Http\Controllers\Auth\LoginOtpController::class, 'verify'])->name('otp.verify');
    Route::post('login/verify/resend', [\App\Http\Controllers\Auth\LoginOtpController::class, 'resend'])->name('otp.resend');
});
require __DIR__.'/settings.php';
