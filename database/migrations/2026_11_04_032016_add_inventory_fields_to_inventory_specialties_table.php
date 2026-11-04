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
        Schema::table('inventory_specialties', function (Blueprint $table) {
            $table->foreignId('inventory_id')
                ->constrained('inventory', 'inventory_id')
                ->cascadeOnDelete();
            $table->string('specialty', 40);
            $table->unique(['inventory_id', 'specialty']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_specialties', function (Blueprint $table) {
            $table->dropUnique('inventory_specialties_inventory_id_specialty_unique');
            $table->dropForeign('inventory_specialties_inventory_id_foreign');
            $table->dropColumn(['inventory_id', 'specialty']);
        });
    }
};
