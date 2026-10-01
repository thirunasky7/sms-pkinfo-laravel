<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 512);
            $table->string('language', 16);
            $table->string('category', 32);
            $table->string('status', 32)->default('draft');
            $table->string('provider_template_id', 64)->nullable();
            $table->string('header_type', 16)->default('none');
            $table->string('header_text')->nullable();
            $table->text('header_media_url')->nullable();
            $table->text('body');
            $table->string('footer', 60)->nullable();
            $table->json('buttons')->nullable();
            $table->json('examples')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'name', 'language']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
