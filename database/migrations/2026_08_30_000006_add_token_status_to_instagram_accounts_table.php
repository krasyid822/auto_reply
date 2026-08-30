<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->timestamp('token_invalid_at')->nullable()->after('token_expires_at');
            $table->timestamp('last_checked_at')->nullable()->after('token_invalid_at');
            $table->boolean('last_check_ok')->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn(['token_invalid_at', 'last_checked_at', 'last_check_ok']);
        });
    }
};
