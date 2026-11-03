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
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('old_email');
            $table->string('new_email')->index();
            $table->string('old_token_hash', 64)->nullable();
            $table->string('new_token_hash', 64);
            $table->timestamp('old_approved_at')->nullable();
            $table->timestamp('new_verified_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
    }
};
