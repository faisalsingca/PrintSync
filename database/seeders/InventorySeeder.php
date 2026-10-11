<?php

namespace Database\Seeders;

use App\Models\Inventory;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * @var array<string, array{quantity: string, unit: string, low_stock_level: string}>
     */
    private const MATERIALS = [
        'Copy Paper 80 gsm' => ['quantity' => '12.000', 'unit' => 'ream', 'low_stock_level' => '3.000'],
        'Coated Paper 150 gsm' => ['quantity' => '500.000', 'unit' => 'sheet', 'low_stock_level' => '100.000'],
        'Cardstock 250 gsm' => ['quantity' => '300.000', 'unit' => 'sheet', 'low_stock_level' => '60.000'],
        'Glossy Photo Paper A4' => ['quantity' => '250.000', 'unit' => 'sheet', 'low_stock_level' => '50.000'],
        'Matte Photo Paper A4' => ['quantity' => '200.000', 'unit' => 'sheet', 'low_stock_level' => '40.000'],
        'Sticker Paper A4' => ['quantity' => '150.000', 'unit' => 'sheet', 'low_stock_level' => '30.000'],
        'Tarpaulin Flex Banner' => ['quantity' => '80.000', 'unit' => 'square meter', 'low_stock_level' => '15.000'],
        'Sintra Board 3 mm' => ['quantity' => '40.000', 'unit' => 'sheet', 'low_stock_level' => '8.000'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::MATERIALS as $materialName => $attributes) {
            $inventory = Inventory::query()->firstOrCreate(
                ['material_name' => $materialName],
                [
                    ...$attributes,
                    'status' => 'in_stock',
                ],
            );

            // Existing rows get their low-stock level and a status that matches their quantity.
            $inventory->low_stock_level = $attributes['low_stock_level'];
            $inventory->status = $inventory->stockStatus();
            $inventory->save();
        }
    }
}