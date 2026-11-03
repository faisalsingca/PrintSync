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
        Schema::create('order_takeovers', function (Blueprint $table) {
            $table->id('takeover_id');
            $table->foreignId('order_id')
                ->constrained('orders', 'order_id')
                ->restrictOnDelete()
                ->unique();
            $table->foreignId('assignment_id')
                ->constrained('order_assignments', 'assignment_id')
                ->restrictOnDelete();
            $table->foreignId('covering_staff_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('taken_over_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_takeovers');
    }
};
