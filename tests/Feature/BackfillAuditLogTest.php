<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryAlert;
use App\Models\StockIn;
use App\Models\User;

function backfillSetup(): array
{
    $cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Old Cashier']);
    $item = Inventory::query()->create([
        'material_name' => 'Vinyl',
        'quantity' => '12.000',
        'unit' => 'roll',
        'low_stock_level' => '5',
        'status' => Inventory::STATUS_IN_STOCK,
    ]);
    StockIn::query()->create([
        'inventory_id' => $item->inventory_id,
        'staff_id' => $cashier->id,
        'quantity_added' => '20.000',
        'date_added' => '2026-10-01 09:00:00',
    ]);
    InventoryAlert::query()->create([
        'inventory_id' => $item->inventory_id,
        'staff_id' => $cashier->id,
        'alert_type' => 'low_stock',
        'alert_date' => '2026-10-02 14:30:00',
        'remarks' => 'Almost out',
    ]);

    return [$cashier, $item];
}

test('the backfill adds earlier inventory activity with the original dates and people', function () {
    [$cashier, $item] = backfillSetup();

    $this->artisan('audit:backfill-inventory')->assertSuccessful();

    $stock = AuditLog::query()->where('action', 'inventory.stock_added')->firstOrFail();
    $alert = AuditLog::query()->where('action', 'inventory.low_stock_reported')->firstOrFail();

    expect(AuditLog::query()->where('action', 'inventory.created')->count())->toBe(1)
        ->and($stock->actor_name)->toBe('Old Cashier')
        ->and($stock->user_id)->toBe($cashier->id)
        ->and($stock->created_at->toDateTimeString())->toBe('2026-10-01 09:00:00')
        ->and($stock->description)->toContain('Added 20 roll to "Vinyl"')
        ->and($stock->isBackfilled())->toBeTrue()
        ->and($alert->created_at->toDateTimeString())->toBe('2026-10-02 14:30:00')
        ->and($alert->description)->toContain('Almost out');
});

test('running the backfill again does not duplicate entries', function () {
    backfillSetup();

    $this->artisan('audit:backfill-inventory')->assertSuccessful();
    $first = AuditLog::query()->count();

    $this->artisan('audit:backfill-inventory')->assertSuccessful();

    expect(AuditLog::query()->count())->toBe($first)->toBe(3);
});

test('a dry run saves nothing', function () {
    backfillSetup();

    $this->artisan('audit:backfill-inventory --dry-run')->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(0);
});

test('the owner sees backfilled entries marked as earlier records', function () {
    backfillSetup();
    $this->artisan('audit:backfill-inventory')->assertSuccessful();

    $this->actingAs(User::factory()->create(['role' => 'owner']))
        ->get(route('owner.audit-logs'))
        ->assertOk()
        ->assertSee('From earlier records')
        ->assertSee('Old Cashier');
});