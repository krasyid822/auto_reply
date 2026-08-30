<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->unsignedBigInteger('ig_user_id')->nullable()->after('id');
            $table->dropUnique(['comment_id']);
            $table->unique(['ig_user_id', 'comment_id']);
            $table->index(['ig_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropUnique(['ig_user_id', 'comment_id']);
            $table->dropIndex(['comments_ig_user_id_index']);
            $table->unique(['comment_id']);
            $table->dropColumn(['ig_user_id']);
        });
    }
};
