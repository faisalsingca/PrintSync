<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('customer')->change();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->where('role', 'super_admin')->exists()) {
            throw new RuntimeException('Super Admin accounts must be removed before this migration can be rolled back.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['customer', 'staff', 'cashier', 'owner'])->default('customer')->change();
            $table->dropColumn(['phone', 'is_active']);
        });
    }
};
