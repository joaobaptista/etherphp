<?php

namespace App\Actions\Subscriptions;

use App\Contracts\PaymentGatewayContract;
use App\Enums\PlanIdEnum;
use App\Models\Capsule;
use App\Models\Guardian;
use App\Models\Memory;
use App\Models\Subscription;
use App\Models\User;
use App\Support\RootAdmin;

class GetCurrentSubscriptionAction
{
    public function __construct(
        protected PaymentGatewayContract $gateway,
    ) {}

    /**
     * @return array{subscription: array<string, mixed>, usage: array<string, mixed>}
     */
    public function execute(User $user): array
    {
        $plans = $this->gateway->plans();
        $dbSub = Subscription::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        // A paid period that is over counts as the free plan even before the
        // scheduled ExpireSubscriptionsAction marks it (auto-renew gets a grace window).
        $graceDays = $dbSub?->auto_renew ? max(0, (int) config('ethernity.mercado_pago.renewal_grace_days', 10)) : 0;
        $isLapsed = $dbSub !== null && (
            $dbSub->status !== 'active'
            || $dbSub->current_period_end->copy()->addDays($graceDays)->isPast()
        );

        $effectivePlanId = match (true) {
            RootAdmin::isRootEmail($user->email) || $user->plan_id === PlanIdEnum::Infinity => 'infinity',
            $dbSub !== null && ! $isLapsed => $dbSub->plan_id->value,
            $dbSub !== null => 'free',
            default => $user->plan_id->value,
        };

        $plan = $plans[$effectivePlanId] ?? $plans['free'] ?? [
            'id' => 'free',
            'name' => 'Gratuito / Essencial',
            'maxCapsules' => 5,
            'maxGuardians' => 2,
            'storageGb' => 5,
            'historyDays' => 30,
        ];

        $subscription = [
            'id' => $dbSub?->id,
            'planId' => $plan['id'],
            'planName' => $plan['name'],
            'status' => $dbSub === null ? 'active' : ($isLapsed && $dbSub->status === 'active' ? 'expired' : $dbSub->status),
            'cycle' => $dbSub !== null ? $dbSub->billing_cycle : 'monthly',
            'maxCapsules' => (int) ($plan['maxCapsules'] ?? 50),
            'maxGuardians' => (int) ($plan['maxGuardians'] ?? 10),
            'storageGb' => (int) ($plan['storageGb'] ?? 100),
            'maxDevices' => 0,
            'maxGeofences' => 0,
            'historyDays' => (int) ($plan['historyDays'] ?? 365),
            'expiresAt' => $dbSub?->current_period_end->toIso8601String(),
            'autoRenew' => (bool) $dbSub?->auto_renew,
            'canceledAt' => $dbSub?->canceled_at?->toIso8601String(),
            'paymentMethod' => $dbSub?->payment_method,
        ];

        $capsulesCount = Capsule::query()->where('user_id', $user->id)->count();
        $guardiansCount = Guardian::query()->where('user_id', $user->id)->count();
        $usedBytes = (int) Memory::query()->where('author_id', $user->id)->sum('file_size_bytes');

        return [
            'subscription' => $subscription,
            'usage' => [
                'capsulesCount' => $capsulesCount,
                'capsulesLimit' => $subscription['maxCapsules'],
                'guardiansCount' => $guardiansCount,
                'guardiansLimit' => $subscription['maxGuardians'],
                'storageGbUsed' => round($usedBytes / (1024 * 1024 * 1024), 4),
                'storageGbLimit' => $subscription['storageGb'],
                'devicesCount' => 0,
                'devicesLimit' => 0,
                'areasCount' => 0,
                'areasLimit' => 0,
            ],
        ];
    }
}
