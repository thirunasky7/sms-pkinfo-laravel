<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Encrypted with APP_KEY so customers can view it in the dashboard.
            // Authentication still verifies secret_hash. Null for keys created before this column.
            $table->text('secret_encrypted')->nullable()->after('secret_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('secret_encrypted');
        });
    }
};
