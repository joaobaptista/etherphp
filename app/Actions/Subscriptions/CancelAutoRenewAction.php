<?php

namespace App\Actions\Subscriptions;

use App\Contracts\PaymentGatewayContract;
use App\Models\Subscription;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stops future automatic charges. The plan stays active until the end of the
 * period already paid for and then expires (see ExpireSubscriptionsAction).
 */
class CancelAutoRenewAction
{
    public function __construct(
        protected PaymentGatewayContract $gateway,
    ) {}

    public function execute(User $user): Subscription
    {
        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('auto_renew', true)
            ->whereNotNull('gateway_subscription_id')
            ->first();

        if ($subscription === null) {
            throw new HttpException(404, 'Nenhuma assinatura com renovação automática encontrada.');
        }

        $this->gateway->cancelPreapproval((string) $subscription->gateway_subscription_id);

        $subscription->update([
            'auto_renew' => false,
            'gateway_status' => 'canceled',
            'canceled_at' => now(),
        ]);

        return $subscription->refresh();
    }
}
