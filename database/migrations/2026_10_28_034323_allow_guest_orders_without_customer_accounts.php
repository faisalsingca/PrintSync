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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('customer_name')->nullable()->after('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Guest orders must be assigned to accounts before this migration can be rolled back.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_name');
            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
