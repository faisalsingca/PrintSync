<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function inventoryTestStaff(string $specialty): User
{
    $staff = User::factory()->create(['role' => 'staff']);
    StaffSpecialty::query()->create(['staff_id' => $staff->id, 'specialty' => $specialty]);

    return $staff;
}

function inventoryTestCashier(): User
{
    return User::factory()->create(['role' => 'cashier']);
}

function inventoryTestItem(string $name = 'Vinyl', string $quantity = '4.000'): Inventory
{
    return Inventory::query()->create([
        'material_name' => $name,
        'quantity' => $quantity,
        'unit' => 'roll',
        'low_stock_level' => '10',
        'status' => Inventory::STATUS_LOW_STOCK,
    ]);
}

test('the cashier can add a material and it is written to the audit log', function () {
    $cashier = inventoryTestCashier();
    $this->actingAs($cashier);

    Livewire::test('pages::cashier.inventory-add')
        ->set('material_name', 'Foam Board')
        ->set('quantity', '10')
        ->set('unit', 'sheet')
        ->call('createMaterial')
        ->assertHasNoErrors()
        ->assertRedirect(route('cashier.inventory'));

    $item = Inventory::query()->where('material_name', 'Foam Board')->firstOrFail();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'inventory.created',
        'user_id' => $cashier->id,
        'actor_name' => $cashier->name,
        'subject_id' => $item->inventory_id,
        'subject_label' => 'Foam Board',
    ]);
});

test('material names must be unique even when the other material is archived', function () {
    $existing = inventoryTestItem('Cardstock', '5.000');
    (new InventoryService)->archiveMaterial($existing);
    $this->actingAs(inventoryTestCashier());

    Livewire::test('pages::cashier.inventory-add')
        ->set('material_name', 'Cardstock')
        ->set('quantity', '-1')
        ->set('unit', 'sheet')
        ->call('createMaterial')
        ->assertHasErrors(['material_name' => 'unique', 'quantity' => 'min']);

    $this->assertDatabaseCount('inventory', 1);
});

test('the cashier can add and deduct stock and each change is audited', function () {
    $cashier = inventoryTestCashier();
    $item = inventoryTestItem('Ink', '4.000');
    $this->actingAs($cashier);

    Livewire::test('pages::cashier.inventory')
        ->set("stockAdd.{$item->inventory_id}", '10')
        ->call('addStock', $item->inventory_id)
        ->assertHasNoErrors()
        ->set("deduct.{$item->inventory_id}", '3')
        ->set("deductNote.{$item->inventory_id}", 'Used for order 12')
        ->call('deductStock', $item->inventory_id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '11.000']);
    $this->assertDatabaseHas('stock_in', ['inventory_id' => $item->inventory_id, 'staff_id' => $cashier->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.stock_added', 'subject_id' => $item->inventory_id]);

    $deduction = AuditLog::query()->where('action', 'inventory.stock_deducted')->firstOrFail();

    expect($deduction->description)->toContain('Used for order 12')
        ->and($deduction->user_id)->toBe($cashier->id);
});

test('the cashier cannot deduct more than what is left', function () {
    $item = inventoryTestItem('Ink', '2.000');
    $this->actingAs(inventoryTestCashier());

    Livewire::test('pages::cashier.inventory')
        ->set("deduct.{$item->inventory_id}", '5')
        ->call('deductStock', $item->inventory_id)
        ->assertHasErrors(["deduct.{$item->inventory_id}"]);

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '2.000']);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'inventory.stock_deducted']);
});

test('editing a material records what changed', function () {
    $item = inventoryTestItem('Vinyl');
    $this->actingAs(inventoryTestCashier());

    Livewire::test('pages::cashier.inventory')
        ->call('startEdit', $item->inventory_id)
        ->set('edit_name', 'Vinyl Roll')
        ->set('edit_low_stock_level', '20')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'material_name' => 'Vinyl Roll', 'low_stock_level' => '20.000']);

    $log = AuditLog::query()->where('action', 'inventory.updated')->firstOrFail();

    expect($log->description)->toContain('Vinyl → Vinyl Roll')
        ->and($log->description)->toContain('low-stock level: 10 → 20');
});

test('archiving hides a material and restoring brings it back', function () {
    $item = inventoryTestItem('Banner Mesh', '30.000');
    $this->actingAs(inventoryTestCashier());

    $component = Livewire::test('pages::cashier.inventory')
        ->assertSee('Banner Mesh')
        ->call('archiveMaterial', $item->inventory_id);

    expect($component->get('inventory')->pluck('material_name')->all())->not->toContain('Banner Mesh');

    expect($item->fresh()->archived_at)->not->toBeNull();

    $this->get(route('cashier.inventory.archive'))
        ->assertOk()
        ->assertSee('Banner Mesh');

    Livewire::test('pages::cashier.inventory-archive')
        ->call('restore', $item->inventory_id);

    expect($item->fresh()->archived_at)->toBeNull();

    Livewire::test('pages::cashier.inventory')->assertSee('Banner Mesh');

    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.archived', 'subject_id' => $item->inventory_id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.restored', 'subject_id' => $item->inventory_id]);
    $this->assertDatabaseCount('inventory', 1);
});

test('stock cannot be added to or deducted from an archived material', function () {
    $cashier = inventoryTestCashier();
    $item = inventoryTestItem('Old Stock', '5.000');
    $service = new InventoryService;
    $service->archiveMaterial($item, $cashier);

    expect(fn () => $service->addStock($item->fresh(), $cashier, '1'))->toThrow(ValidationException::class)
        ->and(fn () => $service->deductStock($item->fresh(), $cashier, '1'))->toThrow(ValidationException::class);

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '5.000']);
});

test('archived materials are left out of the lists, counts and open reports', function () {
    $service = new InventoryService;
    $kept = inventoryTestItem('Kept', '4.000');
    $gone = inventoryTestItem('Gone', '4.000');
    $staff = inventoryTestStaff('sewer');
    $service->createLowStockAlert($gone, $staff, 'Low');
    $service->archiveMaterial($gone);

    expect($service->getInventoryList()->pluck('material_name')->all())->toBe(['Kept'])
        ->and($service->getInventorySummary()['inventory_count'])->toBe(1)
        ->and($service->getInventorySummary()['archived_count'])->toBe(1)
        ->and($service->getOpenLowStockAlerts())->toHaveCount(0);
});

test('staff of any specialty except graphic design can report low stock and the cashier receives it', function (string $specialty) {
    $staff = inventoryTestStaff($specialty);
    $item = inventoryTestItem('Sticker Paper');
    $this->actingAs($staff);

    Livewire::test('pages::staff.inventory')
        ->set("alertRemarks.{$item->inventory_id}", 'Only a few left')
        ->call('reportLowStock', $item->inventory_id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory_alerts', [
        'inventory_id' => $item->inventory_id,
        'staff_id' => $staff->id,
        'alert_type' => 'low_stock',
        'remarks' => 'Only a few left',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.low_stock_reported', 'user_id' => $staff->id]);

    $this->actingAs(inventoryTestCashier());

    $this->get(route('cashier.low-stock-reports'))
        ->assertOk()
        ->assertSee('Sticker Paper')
        ->assertSee($staff->name)
        ->assertSee('Only a few left');
})->with([
    'sewer' => ['sewer'],
    'machine operator' => ['machine_operator'],
    'book binder' => ['book_binder'],
]);

test('graphic designers cannot open the low-stock page or report low stock', function () {
    $this->actingAs(inventoryTestStaff('graphic_designer'));

    $this->get(route('staff.inventory'))->assertForbidden();

    expect(auth()->user()->can('createLowStockAlert', Inventory::class))->toBeFalse();
});

test('a staff member cannot send the same unhandled report twice', function () {
    $item = inventoryTestItem();
    $this->actingAs(inventoryTestStaff('book_binder'));

    Livewire::test('pages::staff.inventory')
        ->call('reportLowStock', $item->inventory_id)
        ->call('reportLowStock', $item->inventory_id);

    $this->assertDatabaseCount('inventory_alerts', 1);
});

test('a report is cleared once the cashier adds stock', function () {
    $cashier = inventoryTestCashier();
    $item = inventoryTestItem();
    $service = new InventoryService;
    $service->createLowStockAlert($item, inventoryTestStaff('sewer'), 'Low', now()->subMinute());

    expect($service->getOpenLowStockAlerts())->toHaveCount(1);

    $service->addStock($item, $cashier, '20');

    expect($service->getOpenLowStockAlerts())->toHaveCount(0)
        ->and($service->getInventorySummary()['open_low_stock_alert_count'])->toBe(0);
});

test('staff cannot manage inventory and the cashier cannot report as staff', function () {
    $this->actingAs(inventoryTestStaff('sewer'));
    $this->get(route('cashier.inventory'))->assertForbidden();

    $this->actingAs(inventoryTestCashier());
    $this->get(route('staff.inventory'))->assertForbidden();
});
