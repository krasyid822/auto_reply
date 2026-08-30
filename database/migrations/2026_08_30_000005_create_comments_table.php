<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log komentar Instagram + status pemrosesan.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comment_id')->unique();
            $table->unsignedBigInteger('media_id');
            $table->string('media_type')->nullable();
            $table->text('text')->nullable();
            $table->string('username')->nullable();
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('reply_text')->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('auto_reply_rules')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['media_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
