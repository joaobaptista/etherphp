<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 160);
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('plan_id', 32)->default('infinity')->index();
            $table->boolean('is_admin')->default(false)->index();
            $table->timestamp('notifications_read_at', 3)->nullable();
            $table->text('encryption_public_key')->nullable();
            $table->rememberToken();
            $table->timestamps(3);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
