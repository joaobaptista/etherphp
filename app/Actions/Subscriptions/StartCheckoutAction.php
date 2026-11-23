<?php

namespace App\Actions\Subscriptions;

use App\Contracts\PaymentGatewayContract;
use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use App\Enums\PlanIdEnum;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Models\User;
use App\Support\RootAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Starts a real Mercado Pago checkout for a paid plan:
 * - card: recurring subscription (hosted page, automatic renewal);
 * - pix:  one-off payment for one billing period.
 *
 * The price always comes from config('ethernity.plans'), never from the client.
 */
class StartCheckoutAction
{
    public function __construct(
        protected PaymentGatewayContract $gateway,
    ) {}

    public function execute(User $user, string $planId, string $cycle, CheckoutMethodEnum $method): PaymentCheckout
    {
        if (! $this->gateway->isConfigured()) {
            throw new HttpException(503, 'Pagamentos online ainda não estão disponíveis.');
        }

        $plan = $this->gateway->plans()[$planId] ?? null;
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $amount = $plan === null ? 0.0 : (float) ($cycle === 'yearly' ? ($plan['priceYearly'] ?? 0) : ($plan['priceMonthly'] ?? 0));

        if ($plan === null || $planId === PlanIdEnum::Infinity->value || $amount <= 0) {
            throw new HttpException(422, 'Escolha um plano pago válido.');
        }

        if ($user->plan_id === PlanIdEnum::Infinity || RootAdmin::isRootEmail($user->email)) {
            throw new HttpException(409, 'Sua conta já tem o plano ilimitado.');
        }

        // Never stack a second automatic charge on top of an active one.
        $hasAutoRenew = Subscription::query()
            ->where('user_id', $user->id)
            ->where('auto_renew', true)
            ->exists();
        if ($hasAutoRenew) {
            throw new HttpException(409, 'Você já tem uma assinatura com renovação automática. Cancele a renovação antes de trocar de plano ou pagar com PIX.');
        }

        // Reopening the checkout must not create a new PIX charge (or a new pending
        // subscription on Mercado Pago) each time.
        $reusable = PaymentCheckout::query()
            ->where('user_id', $user->id)
            ->where('method', $method->value)
            ->where('plan_id', $planId)
            ->where('billing_cycle', $cycle)
            ->where('status', CheckoutStatusEnum::Pending->value)
            ->when(
                $method === CheckoutMethodEnum::Pix,
                fn ($query) => $query->whereNotNull('pix_qr_code')->where('expires_at', '>', now()->addMinutes(5)),
                fn ($query) => $query->whereNotNull('init_point')->where('created_at', '>', now()->subDay()),
            )
            ->latest()
            ->first();
        if ($reusable !== null) {
            return $reusable;
        }

        $checkout = PaymentCheckout::query()->create([
            'id' => 'chk_'.Str::uuid()->toString(),
            'user_id' => $user->id,
            'plan_id' => $planId,
            'billing_cycle' => $cycle,
            'method' => $method,
            'amount' => $amount,
            'status' => CheckoutStatusEnum::Pending,
        ]);

        $label = sprintf(
            'Ethernity DMs — Plano %s (%s)',
            (string) ($plan['name'] ?? $planId),
            $cycle === 'yearly' ? 'Anual' : 'Mensal',
        );

        try {
            if ($method === CheckoutMethodEnum::Card) {
                $subscription = $this->gateway->createRecurringSubscription($checkout, $user, $label);
                $checkout->update([
                    'gateway_id' => $subscription['id'],
                    'gateway_status' => $subscription['status'],
                    'init_point' => $subscription['init_point'],
                ]);
            } else {
                $pix = $this->gateway->createPixPayment($checkout, $user, $label);
                $checkout->update([
                    'gateway_id' => $pix['id'],
                    'gateway_status' => $pix['status'],
                    'pix_qr_code' => $pix['qr_code'],
                    'pix_qr_code_base64' => $pix['qr_code_base64'],
                    'pix_ticket_url' => $pix['ticket_url'],
                    // Mercado Pago answers with an offset (e.g. -03:00/-04:00); Eloquent would store
                    // the wall-clock time as-is, so convert to the app timezone first.
                    'expires_at' => CarbonImmutable::parse($pix['expires_at'])->setTimezone((string) config('app.timezone', 'UTC')),
                ]);
            }
        } catch (\Throwable $e) {
            $checkout->update(['status' => CheckoutStatusEnum::Canceled, 'gateway_status' => 'error']);

            throw $e;
        }

        return $checkout->refresh();
    }
}
