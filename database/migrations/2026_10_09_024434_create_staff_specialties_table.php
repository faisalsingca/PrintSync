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
        Schema::create('staff_specialties', function (Blueprint $table) {
            $table->id('staff_specialty_id');
            $table->foreignId('staff_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('specialty', 40);
            $table->timestamps();

            $table->unique(['staff_id', 'specialty']);
            $table->index('specialty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_specialties');
    }
};
