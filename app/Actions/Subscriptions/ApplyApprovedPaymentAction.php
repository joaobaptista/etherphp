<?php

namespace App\Actions\Subscriptions;

use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grants one billing period for an approved Mercado Pago payment.
 *
 * Every path that observes an approved payment (payment webhook, recurring-charge
 * webhook, PIX polling) ends here. The Mercado Pago payment id is the primary key
 * of subscription_payments, so the same payment never extends a plan twice.
 */
class ApplyApprovedPaymentAction
{
    public function __construct(
        protected ActivateSubscriptionAction $activate,
    ) {}

    /**
     * @return bool True when this call granted the period, false when it was already applied or refused.
     */
    public function execute(PaymentCheckout $checkout, string $paymentId, float $paidAmount): bool
    {
        if ($paymentId === '') {
            return false;
        }

        // Amount always comes from Mercado Pago's API, never from the notification body.
        if ($paidAmount + 0.009 < (float) $checkout->amount) {
            Log::warning('Mercado Pago payment below plan price; access not granted.', [
                'payment_id' => $paymentId,
                'checkout_id' => $checkout->id,
                'paid' => $paidAmount,
                'expected' => (float) $checkout->amount,
            ]);

            return false;
        }

        $user = User::query()->find($checkout->user_id);
        if ($user === null) {
            return false;
        }

        return DB::transaction(function () use ($checkout, $paymentId, $paidAmount, $user): bool {
            // Serializes concurrent deliveries of the same payment (webhook + polling).
            $current = Subscription::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if (SubscriptionPayment::query()->whereKey($paymentId)->exists()) {
                return false;
            }

            $isRecurring = $checkout->method === CheckoutMethodEnum::Card;

            // A renewal (or an early payment for the same plan) continues from the end of the
            // current period instead of throwing away the days already paid for.
            $periodStart = now();
            if (
                $current !== null
                && $current->status === 'active'
                && $current->plan_id->value === $checkout->plan_id
                && $current->current_period_end->isFuture()
            ) {
                $periodStart = $current->current_period_end;
            }

            $attributes = $isRecurring
                ? [
                    'gateway_subscription_id' => $checkout->gateway_id,
                    'gateway_status' => 'authorized',
                    'auto_renew' => true,
                    'canceled_at' => null,
                ]
                : [];

            $result = $this->activate->execute(
                $user,
                $checkout->plan_id,
                $checkout->billing_cycle,
                $isRecurring ? 'mercado_pago_card_recurring' : 'mercado_pago_pix',
                ['lastPaymentId' => $paymentId],
                $periodStart,
                $attributes,
            );

            SubscriptionPayment::query()->create([
                'id' => $paymentId,
                'user_id' => $user->id,
                'checkout_id' => $checkout->id,
                'kind' => $isRecurring ? 'recurring' : 'pix',
                'amount' => $paidAmount,
                'plan_id' => $checkout->plan_id,
                'billing_cycle' => $checkout->billing_cycle,
                'period_start' => $periodStart,
                'period_end' => (string) $result['expiresAt'],
            ]);

            $checkout->update([
                'status' => CheckoutStatusEnum::Approved,
                'gateway_status' => $isRecurring ? 'authorized' : 'approved',
            ]);

            return true;
        });
    }
}
