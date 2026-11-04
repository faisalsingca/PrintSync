<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_specialties', 'inventory_id')
            || ! Schema::hasColumn('inventory_specialties', 'specialty')) {
            Schema::table('inventory_specialties', function (Blueprint $table): void {
                if (! Schema::hasColumn('inventory_specialties', 'inventory_id')) {
                    $table->foreignId('inventory_id');
                }

                if (! Schema::hasColumn('inventory_specialties', 'specialty')) {
                    $table->string('specialty', 40)->default('all');
                }
            });
        }

        $inventoryForeignKeyExists = collect(Schema::getForeignKeys('inventory_specialties'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['inventory_id']
                && $foreignKey['foreign_table'] === 'inventory'
                && $foreignKey['foreign_columns'] === ['inventory_id']);

        if (! $inventoryForeignKeyExists) {
            Schema::table('inventory_specialties', function (Blueprint $table): void {
                $table->foreign('inventory_id', 'inventory_specialties_inventory_id_foreign')
                    ->references('inventory_id')
                    ->on('inventory')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasIndex('inventory_specialties', ['inventory_id', 'specialty'], 'unique')) {
            Schema::table('inventory_specialties', function (Blueprint $table): void {
                $table->unique(['inventory_id', 'specialty']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('inventory_specialties', ['inventory_id', 'specialty'], 'unique')) {
            Schema::table('inventory_specialties', function (Blueprint $table): void {
                $table->dropUnique('inventory_specialties_inventory_id_specialty_unique');
            });
        }

        if (Schema::hasForeignKey('inventory_specialties', ['inventory_id'])) {
            Schema::table('inventory_specialties', function (Blueprint $table): void {
                $table->dropForeign('inventory_specialties_inventory_id_foreign');
            });
        }

        Schema::table('inventory_specialties', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['inventory_id', 'specialty'],
                fn (string $column): bool => Schema::hasColumn('inventory_specialties', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
