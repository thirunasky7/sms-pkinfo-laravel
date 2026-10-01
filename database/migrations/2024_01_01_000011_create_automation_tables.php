<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 16)->default('whatsapp');
            $table->string('trigger', 32);
            $table->string('match_type', 16)->default('any');
            $table->json('keywords')->nullable();
            $table->string('action', 32);
            $table->json('action_config')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('stop_processing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('trigger_count')->default(0);
            $table->dateTime('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'channel', 'trigger', 'is_active']);
        });

        Schema::create('contact_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('phone', 32);
            $table->string('source', 32)->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_opt_outs');
        Schema::dropIfExists('automation_rules');
    }
};
