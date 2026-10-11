<?php
namespace Database\Seeders;

use App\Models\Inventory;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * @var array<string, array{quantity: string, unit: string}>
     */
    private const MATERIALS = [
        'Copy Paper 80 gsm' => ['quantity' => '12.000', 'unit' => 'ream'],
        'Coated Paper 150 gsm' => ['quantity' => '500.000', 'unit' => 'sheet'],
        'Cardstock 250 gsm' => ['quantity' => '300.000', 'unit' => 'sheet'],
        'Glossy Photo Paper A4' => ['quantity' => '250.000', 'unit' => 'sheet'],
        'Matte Photo Paper A4' => ['quantity' => '200.000', 'unit' => 'sheet'],
        'Sticker Paper A4' => ['quantity' => '150.000', 'unit' => 'sheet'],
        'Tarpaulin Flex Banner' => ['quantity' => '80.000', 'unit' => 'square meter'],
        'Sintra Board 3 mm' => ['quantity' => '40.000', 'unit' => 'sheet'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::MATERIALS as $materialName => $attributes) {
            Inventory::query()->firstOrCreate(
                ['material_name' => $materialName],
                [
                    ...$attributes,
                    'status' => 'available',
                ],
            );
        }
    }
}
