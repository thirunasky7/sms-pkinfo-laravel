<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('connector_type', 16);
            $table->string('status', 32)->default('pending');
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_number', 32)->nullable();
            $table->string('display_name')->nullable();
            $table->string('business_id', 64)->nullable();
            $table->string('waba_id', 64)->nullable();
            $table->string('phone_number_id', 64)->nullable()->unique();
            $table->text('access_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('disconnected_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'connector_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
