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
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id('alert_id');
            $table->foreignId('inventory_id')
                ->constrained('inventory', 'inventory_id')
                ->restrictOnDelete();
            $table->foreignId('staff_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->enum('alert_type', ['low_stock'])->default('low_stock');
            $table->dateTime('alert_date')->useCurrent();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
