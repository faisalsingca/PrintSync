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
        Schema::create('order_assignments', function (Blueprint $table) {
            $table->id('assignment_id');
            $table->foreignId('order_id')
                ->constrained('orders', 'order_id')
                ->restrictOnDelete();
            $table->foreignId('staff_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->dateTime('assigned_date')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_assignments');
    }
};
