<?php

use App\Models\Inventory;
use App\Models\StaffSpecialty;
use App\Models\User;
use App\Services\InventoryService;
use Livewire\Livewire;

function inventoryTestStaff(string $specialty): User
{
    $staff = User::factory()->create(['role' => 'staff']);
    StaffSpecialty::query()->create(['staff_id' => $staff->id, 'specialty' => $specialty]);

    return $staff;
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

test('the cashier can add and delete materials', function () {
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.inventory')
        ->set('material_name', 'Foam Board')
        ->set('quantity', '10')
        ->set('unit', 'sheet')
        ->call('createMaterial')
        ->assertHasNoErrors();

    $item = Inventory::query()->where('material_name', 'Foam Board')->firstOrFail();

    Livewire::test('pages::cashier.inventory')
        ->call('deleteMaterial', $item->inventory_id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('inventory', ['inventory_id' => $item->inventory_id]);
});

test('material names must be unique and quantities valid', function () {
    inventoryTestItem('Cardstock', '5.000');
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.inventory')
        ->set('material_name', 'Cardstock')
        ->set('quantity', '-1')
        ->set('unit', 'sheet')
        ->call('createMaterial')
        ->assertHasErrors(['material_name' => 'unique', 'quantity' => 'min']);

    $this->assertDatabaseCount('inventory', 1);
});

test('the cashier can add and deduct stock', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $item = inventoryTestItem('Ink', '4.000');
    $this->actingAs($cashier);

    Livewire::test('pages::cashier.inventory')
        ->set("stockAdd.{$item->inventory_id}", '10')
        ->call('addStock', $item->inventory_id)
        ->assertHasNoErrors()
        ->set("deduct.{$item->inventory_id}", '3')
        ->call('deductStock', $item->inventory_id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '11.000']);
    $this->assertDatabaseHas('stock_in', ['inventory_id' => $item->inventory_id, 'staff_id' => $cashier->id]);
});

test('the cashier cannot deduct more than what is left', function () {
    $item = inventoryTestItem('Ink', '2.000');
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.inventory')
        ->set("deduct.{$item->inventory_id}", '5')
        ->call('deductStock', $item->inventory_id)
        ->assertHasErrors(["deduct.{$item->inventory_id}"]);

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '2.000']);
});

test('materials with stock-in history cannot be deleted', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $item = inventoryTestItem('Ink', '1.000');
    (new InventoryService)->addStock($item, $cashier, '2.000');
    $this->actingAs($cashier);

    Livewire::test('pages::cashier.inventory')
        ->call('deleteMaterial', $item->inventory_id)
        ->assertSet('deleteError', 'This material has stock or alert records and cannot be deleted. Deduct its remaining stock instead.');

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id]);
});

test('staff of any specialty can report low stock and the cashier receives it', function (string $specialty) {
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

    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    Livewire::test('pages::cashier.inventory')
        ->assertSee('Sticker Paper')
        ->assertSee($staff->name)
        ->assertSee('Only a few left');
})->with([
    'sewer' => ['sewer'],
    'machine operator' => ['machine_operator'],
    'inventory clerk' => ['inventory_clerk'],
]);

test('a staff member cannot send the same unhandled report twice', function () {
    $item = inventoryTestItem();
    $this->actingAs(inventoryTestStaff('book_binder'));

    Livewire::test('pages::staff.inventory')
        ->call('reportLowStock', $item->inventory_id)
        ->call('reportLowStock', $item->inventory_id);

    $this->assertDatabaseCount('inventory_alerts', 1);
});

test('a report is cleared once the cashier adds stock', function () {
    $cashier = User::factory()->create(['role' => 'cashier']);
    $item = inventoryTestItem();
    $service = new InventoryService;
$service->createLowStockAlert($item, inventoryTestStaff('sewer'), 'Low', \Illuminate\Support\Carbon::now()->subMinute());

    expect($service->getOpenLowStockAlerts())->toHaveCount(1);

    $service->addStock($item, $cashier, '20');

    expect($service->getOpenLowStockAlerts())->toHaveCount(0)
        ->and($service->getInventorySummary()['open_low_stock_alert_count'])->toBe(0);
});

test('staff can view inventory but cannot manage it', function () {
    $item = inventoryTestItem();
    $this->actingAs(inventoryTestStaff('sewer'));

    Livewire::test('pages::staff.inventory')
        ->assertSee('Vinyl')
        ->assertDontSee('Add stock')
        ->assertDontSee('Deduct');

    $this->get(route('cashier.inventory'))->assertForbidden();
    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '4.000']);
});

test('the inventory specialty no longer gives staff any inventory management power', function () {
    $this->actingAs(inventoryTestStaff('inventory_clerk'));

    expect(auth()->user()->can('manage', Inventory::class))->toBeFalse()
        ->and(auth()->user()->can('addStock', Inventory::class))->toBeFalse();

    $this->get(route('cashier.inventory'))->assertForbidden();
});

test('the cashier cannot report low stock as staff', function () {
    $item = inventoryTestItem();
    $this->actingAs(User::factory()->create(['role' => 'cashier']));

    $this->get(route('staff.inventory'))->assertForbidden();
    expect(auth()->user()->can('createLowStockAlert', Inventory::class))->toBeFalse();
    $this->assertDatabaseCount('inventory_alerts', 0);
});
test('graphic designers cannot open the low-stock page or report low stock', function () {
    $this->actingAs(inventoryTestStaff('graphic_designer'));

    $this->get(route('staff.inventory'))->assertForbidden();
    expect(auth()->user()->can('createLowStockAlert', Inventory::class))->toBeFalse();
});