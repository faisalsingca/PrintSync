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
        Schema::create('stock_in', function (Blueprint $table) {
            $table->id('stock_in_id');
            $table->foreignId('inventory_id')
                ->constrained('inventory', 'inventory_id')
                ->restrictOnDelete();
            $table->foreignId('staff_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->decimal('quantity_added', total: 12, places: 3);
            $table->dateTime('date_added')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_in');
    }
};
