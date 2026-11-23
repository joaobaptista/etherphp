<?php

namespace App\Actions\Subscriptions;

use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentCheckout;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes a pending checkout straight from Mercado Pago, so the app does not
 * depend on webhook delivery to notice a paid PIX or a completed subscription.
 */
class SyncCheckoutAction
{
    public function __construct(
        protected HandleMercadoPagoNotificationAction $notifications,
    ) {}

    public function execute(PaymentCheckout $checkout): PaymentCheckout
    {
        if ($checkout->status !== CheckoutStatusEnum::Pending || $checkout->gateway_id === null) {
            return $checkout;
        }

        try {
            if ($checkout->method === CheckoutMethodEnum::Pix) {
                $this->notifications->handlePayment($checkout->gateway_id);
            } else {
                $this->notifications->handlePreapproval($checkout->gateway_id);
            }
        } catch (PaymentGatewayException $e) {
            // Polling is best effort: keep the last known state and let the webhook catch up.
            Log::warning('Could not refresh Mercado Pago checkout.', ['checkout_id' => $checkout->id, 'error' => $e->getMessage()]);
        }

        $checkout->refresh();

        if (
            $checkout->status === CheckoutStatusEnum::Pending
            && $checkout->method === CheckoutMethodEnum::Pix
            && $checkout->expires_at !== null
            && $checkout->expires_at->isPast()
        ) {
            $checkout->update(['status' => CheckoutStatusEnum::Expired]);
        }

        return $checkout;
    }
}
