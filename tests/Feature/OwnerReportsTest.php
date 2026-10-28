<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('owner can view report types and print controls', function () {
    Inventory::query()->create([
        'material_name' => 'Copy paper',
        'quantity' => '1.000',
        'unit' => 'ream',
        'low_stock_level' => '2.000',
        'status' => Inventory::STATUS_LOW_STOCK,
    ]);
    Inventory::query()->create([
        'material_name' => 'Archived ink',
        'quantity' => '0.000',
        'unit' => 'bottle',
        'low_stock_level' => '1.000',
        'status' => Inventory::STATUS_OUT_OF_STOCK,
    ])->forceFill(['archived_at' => now()])->save();

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.reports'))
        ->assertOk();

    Livewire::test('pages::owner.reports')
        ->assertSee('Sales & Payments')
        ->assertSee('Print / Save as PDF')
        ->call('selectReport', 'inventory')
        ->assertSee('Current stock levels')
        ->assertSee('Copy paper')
        ->assertSee('Archived ink')
        ->call('selectReport', 'activity')
        ->assertSee('Staff Activity');
});

test('sales and payment reports use the selected date range', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $cashier = User::factory()->create(['role' => 'cashier']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'order_date' => Carbon::parse('2026-10-10 09:00:00'),
        'order_type' => 'Flyers',
        'quantity' => 100,
        'status' => 'pending',
    ]);
    $order->forceFill([
        'confirmed_total' => '1200.00',
        'total_confirmed_at' => Carbon::parse('2026-10-10 10:00:00'),
        'total_confirmed_by_id' => $cashier->id,
    ])->save();
    Payment::query()->create([
        'order_id' => $order->order_id,
        'cashier_id' => $cashier->id,
        'payment_method' => 'cash',
        'received' => '500.00',
        'payment_date' => Carbon::parse('2026-10-10 11:00:00'),
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::owner.reports')
        ->set('fromDate', '2026-10-10')
        ->set('toDate', '2026-10-10')
        ->call('applyFilters')
        ->assertHasNoErrors()
        ->assertSee('1,200.00')
        ->assertSee('500.00')
        ->assertSee('Customer');
});

test('owner activity report includes staff actions in the selected date range', function () {
    $staff = User::factory()->create(['role' => 'staff']);

    AuditLog::query()->create([
        'user_id' => $staff->id,
        'actor_name' => $staff->name,
        'actor_role' => 'staff',
        'action' => 'staff.order_status_updated',
        'description' => 'Updated order #100 status to printing.',
    ]);

    $this->actingAs(User::factory()->create(['role' => 'owner']));

    Livewire::test('pages::owner.reports')
        ->call('selectReport', 'activity')
        ->assertSee('Updated order #100 status to printing.');
});

test('super admin cannot view owner reports', function () {
    $this->actingAs(User::factory()->create(['role' => 'super_admin']))
        ->get(route('owner.reports'))
        ->assertForbidden();
});
