<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->unique();
            $table->string('plan_id', 32)->default('free');
            $table->string('status', 16)->default('active')->index();
            $table->string('price_formatted', 32)->default('R$ 0,00');
            $table->string('billing_cycle', 16)->default('monthly');
            $table->string('payment_method', 32)->nullable();
            $table->dateTime('current_period_start', 3)->useCurrent();
            $table->dateTime('current_period_end', 3);
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 64);
            $table->string('provider', 40);
            $table->string('external_account_id', 255);
            $table->string('display_name', 255)->nullable();
            $table->json('scopes')->nullable();
            $table->mediumText('credentials_encrypted')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'external_account_id'], 'uq_integration_connection');
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('google_photos_picker_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 64);
            $table->string('capsule_id', 64);
            $table->string('google_session_id', 255)->unique('uq_gp_picker_session');
            $table->string('picker_uri', 2048)->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->unsignedInteger('selected_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->json('imported_item_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('selection_ready_at')->nullable();
            $table->dateTime('import_started_at')->nullable();
            $table->dateTime('import_completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at'], 'idx_gp_sessions_user_status');
            $table->index(['capsule_id', 'status', 'updated_at'], 'idx_gp_sessions_capsule_status');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->string('token', 512);
            $table->string('platform', 16)->default('web');
            $table->string('device_model', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);

            $table->unique(['user_id', 'token'], 'uq_device_tokens_user_token');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->string('type', 32);
            $table->string('title', 255);
            $table->text('body');
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->string('delivery_status', 16)->default('pending');
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->dateTime('delivered_at', 3)->nullable();
            $table->text('delivery_error')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('type', 64);
            $table->string('status', 16)->default('pending');
            $table->json('payload');
            $table->timestamp('created_at', 3)->useCurrent();
            $table->dateTime('published_at', 3)->nullable();

            $table->index(['type', 'status', 'created_at']);
        });

        Schema::create('drive_folders', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->string('parent_id', 64)->nullable();
            $table->string('name', 255);
            $table->string('location', 255)->nullable()->default('em Meu Drive');
            $table->string('color', 32)->nullable()->default('#5F6368');
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('drive_files', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64);
            $table->string('folder_id', 64)->nullable()->index();
            $table->string('title', 255);
            $table->string('type', 16);
            $table->string('type_label', 100)->nullable();
            $table->string('icon_color', 32)->nullable();
            $table->string('preview_type', 64)->nullable();
            $table->string('preview_url', 2048)->nullable();
            $table->longText('content')->nullable();
            $table->text('description')->nullable();
            $table->string('size', 64)->nullable();
            $table->string('duration', 64)->nullable();
            $table->string('status', 32)->default('personal_loose');
            $table->string('capsule_id', 64)->nullable()->index();
            $table->string('capsule_recipient', 160)->nullable()->index();
            $table->dateTime('moved_to_capsule_at', 3)->nullable();
            $table->dateTime('moved_to_vault_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('folder_id')->references('id')->on('drive_folders')->nullOnDelete();
            $table->foreign('capsule_id')->references('id')->on('capsules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('google_photos_picker_sessions');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('subscriptions');
    }
};
