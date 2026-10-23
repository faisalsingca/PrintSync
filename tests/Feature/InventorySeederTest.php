<?php

use App\Models\Inventory;
use Database\Seeders\InventorySeeder;

test('inventory seeding adds sample materials once and preserves existing stock', function () {
    $existingSample = Inventory::query()->create([
        'material_name' => 'Copy Paper 80 gsm',
        'quantity' => '2.500',
        'unit' => 'box',
        'status' => 'reserved',
    ]);
    $unrelatedInventory = Inventory::query()->create([
        'material_name' => 'Custom Foil',
        'quantity' => '7.000',
        'unit' => 'roll',
        'status' => 'available',
    ]);

    $this->seed(InventorySeeder::class);

    $sampleMaterials = Inventory::query()
        ->whereIn('material_name', [
            'Copy Paper 80 gsm',
            'Coated Paper 150 gsm',
            'Cardstock 250 gsm',
            'Glossy Photo Paper A4',
            'Matte Photo Paper A4',
            'Sticker Paper A4',
            'Tarpaulin Flex Banner',
            'Sintra Board 3 mm',
        ])
        ->orderBy('material_name')
        ->pluck('inventory_id', 'material_name');

    expect($sampleMaterials->keys()->all())->toBe([
        'Cardstock 250 gsm',
        'Coated Paper 150 gsm',
        'Copy Paper 80 gsm',
        'Glossy Photo Paper A4',
        'Matte Photo Paper A4',
        'Sintra Board 3 mm',
        'Sticker Paper A4',
        'Tarpaulin Flex Banner',
    ]);

    foreach ([
        'Sublimation Fabric',
        'Lamination Film',
        'Printer Ink - Black',
        'Printer Ink - Cyan',
        'Printer Ink - Magenta',
        'Printer Ink - Yellow',
    ] as $removedMaterial) {
        $this->assertDatabaseMissing('inventory', [
            'material_name' => $removedMaterial,
        ]);
    }

    $this->seed(InventorySeeder::class);

    $this->assertDatabaseCount('inventory', 9);
    $this->assertDatabaseHas('inventory', [
        'inventory_id' => $existingSample->inventory_id,
        'material_name' => 'Copy Paper 80 gsm',
        'quantity' => '2.500',
        'unit' => 'box',
        // The seeder keeps the stock but refreshes the status from the quantity (2.5 is below the level of 3).
        'status' => 'low_stock',
    ]);
    $this->assertDatabaseHas('inventory', [
        'inventory_id' => $unrelatedInventory->inventory_id,
        'material_name' => 'Custom Foil',
        'quantity' => '7.000',
    ]);

    expect(
        Inventory::query()
            ->whereIn('material_name', [
                'Copy Paper 80 gsm',
                'Coated Paper 150 gsm',
                'Cardstock 250 gsm',
                'Glossy Photo Paper A4',
                'Matte Photo Paper A4',
                'Sticker Paper A4',
                'Tarpaulin Flex Banner',
                'Sintra Board 3 mm',
            ])
            ->orderBy('material_name')
            ->pluck('inventory_id', 'material_name')
            ->all(),
    )->toBe($sampleMaterials->all());
});
