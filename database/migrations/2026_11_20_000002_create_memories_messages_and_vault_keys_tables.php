<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table): void {
            $table->id();
            $table->string('capsule_id', 64);
            $table->string('author_id', 64);
            $table->string('type', 16)->default('IMAGE');
            $table->text('title')->nullable();
            $table->mediumText('content')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('storage_key', 1024)->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->boolean('is_encrypted')->default(true);
            $table->string('encryption_mode', 32)->default('SINGLE_BLOCK_GCM');
            $table->string('encryption_iv', 64)->nullable();
            $table->string('encryption_tag', 64)->nullable();
            $table->unsignedInteger('chunk_size_bytes')->nullable()->default(65536);
            $table->json('variants_json')->nullable();
            $table->unsignedInteger('media_duration_seconds')->nullable();
            $table->json('waveform_peaks')->nullable();
            $table->unsignedInteger('dpi')->default(300);
            $table->string('color_profile', 32)->default('sRGB');
            $table->string('source', 32)->default('UPLOAD')->index();
            $table->json('provider_metadata')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['capsule_id', 'occurred_at', 'created_at']);
            $table->index(['author_id', 'created_at']);
            $table->index('storage_key');
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->text('title');
            $table->mediumText('content')->nullable();
            $table->text('recipient_name')->nullable();
            $table->string('recipient_email', 255)->nullable();
            $table->string('recipient_phone', 64)->nullable();
            $table->string('delivery_type', 32)->default('date');
            $table->dateTime('delivery_date', 3)->nullable()->index();
            $table->string('delivery_time', 16)->nullable()->default('09:00');
            $table->boolean('guardian_protected')->default(true);
            $table->boolean('email_notification')->default(true);
            $table->boolean('whatsapp_notification')->default(false);
            $table->json('media_files')->nullable();
            $table->string('category', 64)->nullable()->default('Cartas de Amor');
            $table->string('status', 32)->default('scheduled')->index();
            $table->boolean('is_encrypted')->default(true);
            $table->string('device_id', 64)->nullable()->index();
            $table->text('message_text')->nullable();
            $table->string('severity', 32)->nullable()->default('info');
            $table->string('source', 32)->nullable()->default('web');
            $table->boolean('active')->default(true);
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('user_vault_keys', function (Blueprint $table): void {
            $table->string('user_id', 64)->primary();
            $table->integer('version')->default(1);
            $table->string('kdf_name', 32)->default('PBKDF2-SHA256');
            $table->unsignedInteger('kdf_iterations');
            $table->string('kdf_salt', 64);
            $table->string('password_wrapped_key', 255);
            $table->char('password_verifier_hash', 64);
            $table->string('recovery_salt', 64);
            $table->string('recovery_wrapped_key', 255);
            $table->char('recovery_verifier_hash', 64);
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_vault_keys');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('memories');
    }
};
