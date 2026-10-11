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
        Schema::create('orders', function (Blueprint $table) {
            $table->id('order_id');
            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->dateTime('order_date')->useCurrent();
            $table->string('order_type', 50);
            $table->text('description')->nullable();
            $table->string('design_file')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('status', 30)->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
