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
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->nullable()
                ->after('inventory_id')
                ->constrained('orders', 'order_id')
                ->restrictOnDelete();

            $table->index(['order_id', 'staff_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'staff_id', 'status']);
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
