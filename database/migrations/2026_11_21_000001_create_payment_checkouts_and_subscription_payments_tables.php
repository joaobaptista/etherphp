<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Mercado Pago preapproval (recurring card subscription) behind this plan, if any
            $table->string('gateway_subscription_id', 64)->nullable()->unique();
            $table->string('gateway_status', 16)->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->dateTime('canceled_at', 3)->nullable();
        });

        // One row per checkout the user starts (recurring card subscription or one-off PIX).
        // Its id is sent to Mercado Pago as external_reference, which is how notifications
        // are matched back to a user, plan and cycle without trusting the notification body.
        Schema::create('payment_checkouts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->string('plan_id', 32);
            $table->string('billing_cycle', 16);
            $table->string('method', 16);
            $table->decimal('amount', 10, 2);
            $table->string('status', 16)->default('pending')->index();
            $table->string('gateway_id', 64)->nullable()->index();
            $table->string('gateway_status', 32)->nullable();
            $table->string('init_point', 2048)->nullable();
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_qr_code_base64')->nullable();
            $table->string('pix_ticket_url', 2048)->nullable();
            $table->dateTime('expires_at', 3)->nullable();
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Every approved Mercado Pago payment that granted access. The primary key is the
        // Mercado Pago payment id, so a payment delivered twice (webhook retries, polling,
        // both `payment` and `subscription_authorized_payment` topics) is applied only once.
        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->string('checkout_id', 64)->nullable()->index();
            $table->string('kind', 16);
            $table->decimal('amount', 10, 2);
            $table->string('plan_id', 32);
            $table->string('billing_cycle', 16);
            $table->dateTime('period_start', 3);
            $table->dateTime('period_end', 3);
            $table->timestamps(3);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('payment_checkouts');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['gateway_subscription_id']);
            $table->dropColumn(['gateway_subscription_id', 'gateway_status', 'auto_renew', 'canceled_at']);
        });
    }
};
