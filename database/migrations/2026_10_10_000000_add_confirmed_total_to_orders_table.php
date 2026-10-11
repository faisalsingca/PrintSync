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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('confirmed_total', total: 10, places: 2)->nullable()->after('status');
            $table->dateTime('total_confirmed_at')->nullable()->after('confirmed_total');
            $table->foreignId('total_confirmed_by_id')
                ->nullable()
                ->after('total_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('total_confirmed_by_id');
            $table->dropColumn(['confirmed_total', 'total_confirmed_at']);
        });
    }
};
