<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sms_limit');
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->unsignedInteger('duration_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('sms_used')->default(0);
            $table->dateTime('grace_ends_at')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
