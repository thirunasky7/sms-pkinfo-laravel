<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('sender', 64)->nullable();
            $table->string('recipient', 64)->nullable();
            $table->text('body');
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->string('external_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'direction', 'created_at']);
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
