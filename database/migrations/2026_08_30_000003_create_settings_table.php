<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Setelan bot (satu baris).
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('bot_enabled')->default(false);
            $table->unsignedInteger('poll_interval_minutes')->default(5);
            $table->unsignedInteger('max_media_per_cycle')->default(10);
            $table->timestamp('poll_cooldown_until')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->index(['bot_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
