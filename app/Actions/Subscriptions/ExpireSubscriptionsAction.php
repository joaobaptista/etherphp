<?php

namespace App\Actions\Subscriptions;

use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use App\Enums\PlanIdEnum;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled: ends plans whose paid period is over and closes unpaid PIX charges.
 *
 * Auto-renewing plans get `ethernity.mercado_pago.renewal_grace_days` extra days,
 * because Mercado Pago retries a failed recurring charge before canceling.
 */
class ExpireSubscriptionsAction
{
    /**
     * @return array{subscriptions: int, checkouts: int}
     */
    public function execute(): array
    {
        $graceDays = max(0, (int) config('ethernity.mercado_pago.renewal_grace_days', 10));
        $expired = 0;

        Subscription::query()
            ->where('status', 'active')
            ->where('plan_id', '!=', PlanIdEnum::Infinity->value)
            ->where(function ($query) use ($graceDays): void {
                $query->where(fn ($q) => $q->where('auto_renew', false)->where('current_period_end', '<', now()))
                    ->orWhere(fn ($q) => $q->where('auto_renew', true)->where('current_period_end', '<', now()->subDays($graceDays)));
            })
            ->each(function (Subscription $subscription) use (&$expired): void {
                DB::transaction(function () use ($subscription): void {
                    $subscription->update(['status' => 'expired', 'auto_renew' => false]);

                    User::query()
                        ->whereKey($subscription->user_id)
                        ->where('plan_id', '!=', PlanIdEnum::Infinity->value)
                        ->update(['plan_id' => PlanIdEnum::Free->value]);
                });
                $expired++;
            });

        $checkouts = PaymentCheckout::query()
            ->where('method', CheckoutMethodEnum::Pix->value)
            ->where('status', CheckoutStatusEnum::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => CheckoutStatusEnum::Expired->value]);

        return ['subscriptions' => $expired, 'checkouts' => $checkouts];
    }
}
