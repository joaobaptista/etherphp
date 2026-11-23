<?php

namespace App\Actions\Subscriptions;

use App\Contracts\PaymentGatewayContract;
use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentCheckout;
use App\Models\Subscription;

/**
 * Processes a Mercado Pago notification by re-reading the resource from the
 * Mercado Pago API (the notification body is never trusted) and syncing state.
 *
 * Topics: payment, subscription_authorized_payment, subscription_preapproval.
 * Unknown topics, and resources Mercado Pago no longer knows (404), are ignored
 * so they are acknowledged; other gateway errors propagate so the webhook
 * answers 5xx and Mercado Pago retries the delivery.
 */
class HandleMercadoPagoNotificationAction
{
    public function __construct(
        protected PaymentGatewayContract $gateway,
        protected ApplyApprovedPaymentAction $applyPayment,
    ) {}

    public function execute(string $type, string $resourceId): void
    {
        if ($resourceId === '') {
            return;
        }

        try {
            match ($type) {
                'payment' => $this->handlePayment($resourceId),
                'subscription_authorized_payment' => $this->handleAuthorizedPayment($resourceId),
                'subscription_preapproval' => $this->handlePreapproval($resourceId),
                default => null,
            };
        } catch (PaymentGatewayException $e) {
            if ($e->getCode() === 404) {
                return;
            }

            throw $e;
        }
    }

    public function handlePayment(string $paymentId): void
    {
        $payment = $this->gateway->getPayment($paymentId);
        $checkout = $this->checkoutByReference($payment['external_reference']);
        if ($checkout === null) {
            return;
        }

        $this->applyPaymentStatus($checkout, $payment['id'], $payment['status'], $payment['amount']);
    }

    public function handleAuthorizedPayment(string $authorizedPaymentId): void
    {
        $charge = $this->gateway->getAuthorizedPayment($authorizedPaymentId);

        $checkout = $charge['preapproval_id'] !== null
            ? PaymentCheckout::query()
                ->where('method', CheckoutMethodEnum::Card->value)
                ->where('gateway_id', $charge['preapproval_id'])
                ->first()
            : null;
        $checkout ??= $this->checkoutByReference($charge['external_reference']);

        if ($checkout === null || $charge['payment_id'] === null || $charge['payment_status'] === null) {
            return;
        }

        $this->applyPaymentStatus($checkout, $charge['payment_id'], $charge['payment_status'], $charge['amount']);
    }

    public function handlePreapproval(string $preapprovalId): void
    {
        $preapproval = $this->gateway->getPreapproval($preapprovalId);
        $status = $preapproval['status'];

        $checkout = PaymentCheckout::query()
            ->where('method', CheckoutMethodEnum::Card->value)
            ->where('gateway_id', $preapproval['id'])
            ->first();

        if ($checkout !== null) {
            $checkout->gateway_status = $status;
            if (in_array($status, ['canceled', 'cancelled'], true) && $checkout->status === CheckoutStatusEnum::Pending) {
                $checkout->status = CheckoutStatusEnum::Canceled;
            }
            $checkout->save();
        }

        $subscription = Subscription::query()->where('gateway_subscription_id', $preapproval['id'])->first();
        if ($subscription === null) {
            return;
        }

        $subscription->gateway_status = $status;
        if (in_array($status, ['canceled', 'cancelled', 'paused'], true)) {
            // Access stays until the end of the period already paid for.
            $subscription->auto_renew = false;
            if ($status !== 'paused') {
                $subscription->canceled_at ??= now();
            }
        } elseif ($status === 'authorized') {
            $subscription->auto_renew = true;
            $subscription->canceled_at = null;
        }
        $subscription->save();
    }

    private function applyPaymentStatus(PaymentCheckout $checkout, string $paymentId, string $status, float $amount): void
    {
        if ($status === 'approved') {
            $this->applyPayment->execute($checkout, $paymentId, $amount);

            return;
        }

        // A failed one-off PIX closes its checkout; failed recurring charges are retried by
        // Mercado Pago, and the subscription status arrives through subscription_preapproval.
        if ($checkout->method === CheckoutMethodEnum::Pix && $checkout->status === CheckoutStatusEnum::Pending) {
            $mapped = match ($status) {
                'rejected' => CheckoutStatusEnum::Rejected,
                'cancelled', 'canceled' => CheckoutStatusEnum::Canceled,
                default => null,
            };
            $checkout->gateway_status = $status;
            if ($mapped !== null) {
                $checkout->status = $mapped;
            }
            $checkout->save();
        }
    }

    private function checkoutByReference(?string $externalReference): ?PaymentCheckout
    {
        if ($externalReference === null || ! str_starts_with($externalReference, 'chk_')) {
            return null;
        }

        return PaymentCheckout::query()->find($externalReference);
    }
}
