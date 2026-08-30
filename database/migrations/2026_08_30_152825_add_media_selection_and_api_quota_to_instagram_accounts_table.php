<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pilihan pemindaian postingan (terbaru/tetentu) + penghitung kuota API per akun.
     */
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->string('media_mode')->default('recent')->after('username');
            $table->json('media_ids')->nullable()->after('media_mode');

            $table->timestamp('api_calls_window_start')->nullable()->after('last_check_ok');
            $table->unsignedInteger('api_calls_count')->default(0)->after('api_calls_window_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn(['media_mode', 'media_ids', 'api_calls_window_start', 'api_calls_count']);
        });
    }
};
