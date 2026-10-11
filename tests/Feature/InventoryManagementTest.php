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

test('inventory clerks can add, edit, and delete materials', function () {
    $this->actingAs(inventoryTestStaff('inventory_clerk'));

    Livewire::test('pages::staff.inventory')
        ->set('material_name', 'Foam Board')
        ->set('quantity', '10')
        ->set('unit', 'sheet')
        ->set('status', 'available')
        ->call('createMaterial')
        ->assertHasNoErrors();

    $item = Inventory::query()->where('material_name', 'Foam Board')->firstOrFail();

    Livewire::test('pages::staff.inventory')
        ->call('startEdit', $item->inventory_id)
        ->set('edit_quantity', '12.5')
        ->set('edit_status', 'reserved')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory', [
        'inventory_id' => $item->inventory_id,
        'quantity' => '12.500',
        'status' => 'reserved',
    ]);

    Livewire::test('pages::staff.inventory')
        ->call('deleteMaterial', $item->inventory_id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('inventory', ['inventory_id' => $item->inventory_id]);
});

test('material names must be unique and quantities valid', function () {
    Inventory::query()->create(['material_name' => 'Cardstock', 'quantity' => '5.000', 'unit' => 'sheet', 'status' => 'available']);
    $this->actingAs(inventoryTestStaff('inventory_clerk'));

    Livewire::test('pages::staff.inventory')
        ->set('material_name', 'Cardstock')
        ->set('quantity', '-1')
        ->set('unit', 'sheet')
        ->call('createMaterial')
        ->assertHasErrors(['material_name' => 'unique', 'quantity' => 'min']);

    $this->assertDatabaseCount('inventory', 1);
});

test('materials with stock-in history cannot be deleted', function () {
    $clerk = inventoryTestStaff('inventory_clerk');
    $item = Inventory::query()->create(['material_name' => 'Ink', 'quantity' => '1.000', 'unit' => 'bottle', 'status' => 'available']);
    (new InventoryService)->addStock($item, $clerk, '2.000');
    $this->actingAs($clerk);

    Livewire::test('pages::staff.inventory')
        ->call('deleteMaterial', $item->inventory_id)
        ->assertSet('deleteError', 'This material has stock-in or low-stock records and cannot be deleted. Set its status to unavailable instead.');

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id]);
});

test('clerks can add stock and record a low-stock alert from the page', function () {
    $clerk = inventoryTestStaff('inventory_clerk');
    $item = Inventory::query()->create(['material_name' => 'Vinyl', 'quantity' => '4.000', 'unit' => 'roll', 'status' => 'available']);
    $this->actingAs($clerk);

    Livewire::test('pages::staff.inventory')
        ->set("stockAdd.{$item->inventory_id}", '6')
        ->call('addStock', $item->inventory_id)
        ->assertHasNoErrors()
        ->set("alertRemarks.{$item->inventory_id}", 'Reorder soon')
        ->call('reportLowStock', $item->inventory_id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory', ['inventory_id' => $item->inventory_id, 'quantity' => '10.000']);
    $this->assertDatabaseHas('inventory_alerts', ['inventory_id' => $item->inventory_id, 'remarks' => 'Reorder soon']);
});

test('production staff can view inventory but cannot manage it', function () {
    $this->actingAs(inventoryTestStaff('graphic_designer'));

    Livewire::test('pages::staff.inventory')
        ->assertSet('canManage', false)
        ->set('material_name', 'Sneaky')
        ->set('quantity', '1')
        ->set('unit', 'pc')
        ->call('createMaterial')
        ->assertForbidden();

    $this->assertDatabaseMissing('inventory', ['material_name' => 'Sneaky']);
});
