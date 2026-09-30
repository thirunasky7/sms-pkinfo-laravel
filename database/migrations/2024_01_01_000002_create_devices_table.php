<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('imei', 64)->nullable()->index();
            $table->string('sim_number', 32)->nullable()->index();
            $table->string('model')->nullable();
            $table->string('android_version', 32)->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('daily_send_limit')->default(200);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('api_token_hint', 16)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
