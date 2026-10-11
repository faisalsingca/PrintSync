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
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->string('assignment_status', 20)->default('accepted');
            $table->boolean('is_active')->nullable()->default(true);
            $table->unique(['order_id', 'is_active'], 'order_assignments_single_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_assignments', function (Blueprint $table) {
            $table->dropUnique('order_assignments_single_active');
            $table->dropColumn(['assignment_status', 'is_active']);
        });
    }
};
