<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('customer_admin')->after('password');
            $table->string('status', 32)->default('active')->after('role');
            $table->foreignId('parent_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('phone', 32)->nullable()->after('parent_id');
            $table->string('company')->nullable()->after('phone');
            $table->boolean('two_factor_enabled')->default(false)->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['role', 'status', 'phone', 'company', 'two_factor_enabled']);
        });
    }
};
