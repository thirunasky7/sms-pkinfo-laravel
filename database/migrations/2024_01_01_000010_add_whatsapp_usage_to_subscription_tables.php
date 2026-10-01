<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // null = unlimited WhatsApp messages on this plan
            $table->unsignedInteger('whatsapp_limit')->nullable()->after('sms_limit');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('whatsapp_used')->default(0)->after('sms_used');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('whatsapp_used');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('whatsapp_limit');
        });
    }
};
