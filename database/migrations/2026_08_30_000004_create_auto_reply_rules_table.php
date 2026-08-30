<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aturan auto-reply: keyword -> template balasan.
     */
    public function up(): void
    {
        Schema::create('auto_reply_rules', function (Blueprint $table) {
            $table->id();
            $table->string('keyword');
            $table->text('reply_text');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rules');
    }
};
