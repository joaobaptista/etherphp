<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capsules', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->text('title');
            $table->json('recipient');
            $table->string('delivery_type', 32)->default('date');
            $table->dateTime('scheduled_date', 3)->nullable()->index();
            $table->string('emotion', 32)->default('love');
            $table->string('status', 16)->default('LOCKED')->index();
            $table->unsignedInteger('video_duration_seconds')->default(0);
            $table->string('video_blob_url', 2048)->nullable();
            $table->decimal('video_size_mb', 10, 2)->nullable();
            $table->boolean('is_encrypted')->default(true);
            $table->string('encryption_hash', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('capsule_guardians', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('capsule_id', 64);
            $table->string('guardian_email', 191);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['capsule_id', 'guardian_email']);
            $table->foreign('capsule_id')->references('id')->on('capsules')->cascadeOnDelete();
        });

        Schema::create('guardians', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->string('name', 160);
            $table->string('email', 255)->index();
            $table->string('phone', 32)->default('');
            $table->string('relation', 100)->default('');
            $table->string('avatar_url', 512)->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('can_trigger_delivery')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('circle_connections', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('requester_id', 64)->index();
            $table->string('requester_name', 160)->nullable();
            $table->string('requester_email', 255)->nullable();
            $table->string('target_user_id', 64)->nullable()->index();
            $table->string('target_email', 255)->index();
            $table->string('target_name', 160);
            $table->string('relation', 100)->default('');
            $table->string('role', 16)->default('family');
            $table->string('status', 16)->default('active');
            $table->boolean('can_trigger_delivery')->default(true);
            $table->text('notes')->nullable();
            $table->string('invite_code', 32)->nullable()->unique();
            $table->timestamps(3);

            $table->foreign('requester_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_connections');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('capsule_guardians');
        Schema::dropIfExists('capsules');
    }
};
