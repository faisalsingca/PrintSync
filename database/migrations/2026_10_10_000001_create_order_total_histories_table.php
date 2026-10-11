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
        Schema::create('order_total_histories', function (Blueprint $table) {
            $table->id('order_total_history_id');
            $table->foreignId('order_id')
                ->constrained('orders', 'order_id')
                ->restrictOnDelete();
            $table->decimal('previous_total', total: 10, places: 2)->nullable();
            $table->decimal('new_total', total: 10, places: 2);
            $table->foreignId('changed_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_total_histories');
    }
};
