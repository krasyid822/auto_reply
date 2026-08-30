<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ganti media_mode (recent/specific, satu pilihan) menjadi dua flag
     * scan_recent + scan_specific agar kedua mode bisa aktif bersamaan.
     */
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->boolean('scan_recent')->default(true)->after('media_ids');
            $table->boolean('scan_specific')->default(false)->after('scan_recent');
        });

        DB::table('instagram_accounts')->where('media_mode', 'specific')->update([
            'scan_recent' => false,
            'scan_specific' => true,
        ]);

        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn('media_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->string('media_mode')->default('recent')->after('media_ids');
        });

        DB::table('instagram_accounts')->where('scan_specific', true)->update(['media_mode' => 'specific']);

        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn(['scan_recent', 'scan_specific']);
        });
    }
};
