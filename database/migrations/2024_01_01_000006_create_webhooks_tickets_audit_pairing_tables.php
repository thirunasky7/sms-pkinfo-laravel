<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('url');
            $table->string('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('status', 32)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('device_pairing_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_pairing_tokens');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('webhooks');
    }
};
