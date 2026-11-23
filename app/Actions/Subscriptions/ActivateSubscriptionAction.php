<?php

namespace App\Actions\Subscriptions;

use App\Contracts\PaymentGatewayContract;
use App\Enums\PlanIdEnum;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActivateSubscriptionAction
{
    public function __construct(
        protected PaymentGatewayContract $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $extraMeta  Extra keys merged into the returned payload
     * @param  CarbonInterface|null  $periodStart  Start of the paid period (defaults to now; a
     *                                             renewal starts where the current period ends)
     * @param  array<string, mixed>  $subscriptionAttributes  Extra columns (gateway ids, auto_renew…)
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        string $planId,
        string $cycle = 'monthly',
        string $paymentMethod = 'mercado_pago_pix',
        array $extraMeta = [],
        ?CarbonInterface $periodStart = null,
        array $subscriptionAttributes = [],
    ): array {
        $plans = $this->gateway->plans();
        if (! isset($plans[$planId])) {
            throw new HttpException(400, "Plano inválido: {$planId}");
        }

        $plan = $plans[$planId];
        $planEnum = PlanIdEnum::tryFrom($planId) ?? PlanIdEnum::Free;
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $periodStart = CarbonImmutable::instance($periodStart ?? now());
        $periodEnd = $cycle === 'yearly' ? $periodStart->addYearNoOverflow() : $periodStart->addMonthNoOverflow();
        $rawPrice = $cycle === 'yearly'
            ? (float) ($plan['priceYearly'] ?? 0)
            : (float) ($plan['priceMonthly'] ?? 0);
        $priceFormatted = 'R$ '.number_format($rawPrice, 2, ',', '.');

        $subscription = DB::transaction(function () use ($user, $planEnum, $priceFormatted, $cycle, $paymentMethod, $periodStart, $periodEnd, $subscriptionAttributes): Subscription {
            $existing = Subscription::query()->where('user_id', $user->id)->lockForUpdate()->first();

            $attributes = array_merge([
                'plan_id' => $planEnum,
                'status' => 'active',
                'price_formatted' => $priceFormatted,
                'billing_cycle' => $cycle,
                'payment_method' => $paymentMethod,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ], $subscriptionAttributes);

            if ($existing !== null) {
                $existing->update($attributes);
                $record = $existing->refresh();
            } else {
                $record = Subscription::query()->create(array_merge([
                    'id' => 'sub-'.substr((string) Str::uuid(), 0, 12),
                    'user_id' => $user->id,
                ], $attributes));
            }

            $user->update(['plan_id' => $planEnum]);

            return $record;
        });

        return array_merge([
            'id' => $subscription->id,
            'planId' => $plan['id'],
            'planName' => $plan['name'],
            'status' => 'active',
            'cycle' => $cycle,
            'priceFormatted' => $priceFormatted,
            'maxCapsules' => (int) ($plan['maxCapsules'] ?? 50),
            'maxGuardians' => (int) ($plan['maxGuardians'] ?? 10),
            'storageGb' => (int) ($plan['storageGb'] ?? 100),
            'maxDevices' => 0,
            'maxGeofences' => 0,
            'historyDays' => (int) ($plan['historyDays'] ?? 365),
            'expiresAt' => $periodEnd->toIso8601String(),
            'autoRenew' => $subscription->auto_renew,
            'paymentMethod' => $paymentMethod,
            'updatedAt' => now()->toIso8601String(),
        ], $extraMeta);
    }
}
