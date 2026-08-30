<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun Instagram yang terhubung (satu baris; state terpusat).
     */
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ig_user_id')->unique();
            $table->string('username');
            $table->text('access_token'); // encrypted via cast 'encrypted'
            $table->string('token_type')->default('user');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
