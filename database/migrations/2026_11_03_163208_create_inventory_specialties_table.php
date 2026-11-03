<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')
                ->constrained('inventory', 'inventory_id')
                ->cascadeOnDelete();
            $table->string('specialty', 40);
            $table->timestamps();

            $table->unique(['inventory_id', 'specialty']);
            $table->index('specialty');
        });

        DB::table('inventory')->orderBy('inventory_id')->chunk(100, function ($materials): void {
            $timestamp = now();

            DB::table('inventory_specialties')->insert(
                $materials->map(fn (object $material): array => [
                    'inventory_id' => $material->inventory_id,
                    'specialty' => 'all',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all(),
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_specialties');
    }
};
