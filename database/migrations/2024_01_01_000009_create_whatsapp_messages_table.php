<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('connector_type', 16);
            $table->string('message_type', 16)->default('text');
            $table->string('sender', 64)->nullable();
            $table->string('recipient', 64)->nullable();
            $table->text('body')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_mime', 128)->nullable();
            $table->string('media_filename')->nullable();
            $table->json('template_params')->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('provider_message_id', 128)->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('idempotency_key', 128)->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'direction', 'created_at']);
            $table->index(['whatsapp_account_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
