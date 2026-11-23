<?php

use App\Actions\Subscriptions\ExpireSubscriptionsAction;
use App\Contracts\JwtServiceContract;
use App\Enums\PlanIdEnum;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\MercadoPagoWebhookSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const MP_SECRET = 'test-webhook-secret';

beforeEach(function (): void {
    config([
        'ethernity.mercado_pago.access_token' => 'TEST-access-token',
        'ethernity.mercado_pago.webhook_secret' => MP_SECRET,
        'ethernity.mercado_pago.api_base' => 'https://api.mercadopago.com',
        'ethernity.mercado_pago.notification_url' => 'https://app.example.com/api/subscriptions/webhook',
        'ethernity.mercado_pago.back_url' => 'https://app.example.com/',
    ]);
    $this->travelTo(now()->setDate(2026, 11, 21)->setTime(12, 0));
});

function payingUser(): array
{
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free, 'email' => fake()->unique()->safeEmail()]);

    return [$user, app(JwtServiceContract::class)->issueForUser($user)];
}

/** Sends a webhook signed exactly like Mercado Pago (see MercadoPagoWebhookSignature). */
function sendSignedWebhook(string $type, string $id, string $secret = MP_SECRET): TestResponse
{
    $ts = (string) now()->timestamp;
    $requestId = 'req-'.$id;
    $hash = hash_hmac('sha256', "id:{$id};request-id:{$requestId};ts:{$ts};", $secret);

    return test()->withHeaders(['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => $requestId])
        ->postJson("/api/subscriptions/webhook?data.id={$id}&type={$type}", [
            'action' => "{$type}.updated", 'type' => $type, 'data' => ['id' => $id],
        ]);
}

function startCardCheckout(string $token, string $planId = 'family', string $cycle = 'monthly'): array
{
    Http::fake([
        'api.mercadopago.com/preapproval' => Http::response([
            'id' => 'pre_123', 'status' => 'pending', 'init_point' => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_id=pre_123',
        ], 201),
    ]);

    return test()->withToken($token)
        ->postJson('/api/subscriptions/checkout', ['planId' => $planId, 'cycle' => $cycle, 'method' => 'card'])
        ->assertCreated()
        ->json('checkout');
}

it('starts a recurring card subscription with the server-side price and never receives card data', function (): void {
    [$user, $token] = payingUser();

    $checkout = startCardCheckout($token, 'clinical', 'yearly');

    expect($checkout['initPoint'])->toContain('preapproval_id=pre_123')
        ->and($checkout['status'])->toBe('pending')
        ->and($checkout['amount'])->toEqual(799);

    Http::assertSent(function (HttpRequest $request) use ($user, $checkout): bool {
        return $request->url() === 'https://api.mercadopago.com/preapproval'
            && $request->hasHeader('Authorization', 'Bearer TEST-access-token')
            && $request['payer_email'] === $user->email
            && $request['external_reference'] === $checkout['id']
            && $request['status'] === 'pending'
            && $request['auto_recurring']['frequency'] === 12
            && $request['auto_recurring']['frequency_type'] === 'months'
            && (float) $request['auto_recurring']['transaction_amount'] === 799.0
            && $request['auto_recurring']['currency_id'] === 'BRL'
            && str_contains((string) $request['back_url'], 'checkout='.$checkout['id']);
    });

    // Clicking "subscribe" again reuses the pending subscription instead of creating another one
    $again = $this->withToken($token)
        ->postJson('/api/subscriptions/checkout', ['planId' => 'clinical', 'cycle' => 'yearly', 'method' => 'card'])
        ->assertCreated()->json('checkout');
    expect($again['id'])->toBe($checkout['id']);
    Http::assertSentCount(1);

    // The old endpoints that accepted raw card numbers are gone
    $this->withToken($token)->postJson('/api/subscriptions/pay-card', ['planId' => 'family'])->assertNotFound();
    $this->withToken($token)->postJson('/api/subscriptions/create-preference', ['planId' => 'family'])->assertNotFound();
});

it('refuses checkouts when payments are not configured, for free plans and over an active auto-renewal', function (): void {
    [$user, $token] = payingUser();
    Http::fake();

    $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'free', 'method' => 'pix'])->assertStatus(422);
    $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'infinity', 'method' => 'card'])->assertStatus(422);
    $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'family', 'method' => 'boleto'])->assertStatus(422);

    Subscription::query()->create([
        'id' => 'sub-auto', 'user_id' => $user->id, 'plan_id' => 'family', 'status' => 'active',
        'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        'auto_renew' => true, 'gateway_subscription_id' => 'pre_old',
    ]);
    $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'clinical', 'method' => 'card'])
        ->assertStatus(409)
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'renovação automática'));

    config(['ethernity.mercado_pago.access_token' => '']);
    $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'family', 'method' => 'pix'])->assertStatus(503);
    $this->getJson('/api/subscriptions/plans')->assertJsonPath('paymentsEnabled', false);

    Http::assertNothingSent();
});

it('creates a real PIX charge once and reuses it when the checkout is reopened', function (): void {
    [, $token] = payingUser();
    Http::fake([
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 9001, 'status' => 'pending', 'date_of_expiration' => '2026-11-21T09:30:00.000-03:00',
            'point_of_interaction' => ['transaction_data' => [
                'qr_code' => '00020126…PIXCOPIAECOLA', 'qr_code_base64' => 'iVBORw0KGgo=', 'ticket_url' => 'https://mpago.la/pix',
            ]],
        ], 201),
    ]);

    $first = $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'family', 'method' => 'pix'])
        ->assertCreated()->json('checkout');
    $second = $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'family', 'method' => 'pix'])
        ->assertCreated()->json('checkout');

    expect($first['pix']['qrCode'])->toBe('00020126…PIXCOPIAECOLA')
        ->and($first['pix']['qrCodeBase64'])->toBe('iVBORw0KGgo=')
        ->and($second['id'])->toBe($first['id'])
        // 09:30 at -03:00 is 12:30 UTC (30 minutes from "now"), not 09:30 UTC
        ->and($first['pix']['expiresAt'])->toBe('2026-11-21T12:30:00+00:00');

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('X-Idempotency-Key', $first['id'])
        && $request['payment_method_id'] === 'pix'
        && (float) $request['transaction_amount'] === 29.9
        && $request['external_reference'] === $first['id']
        && $request['notification_url'] === 'https://app.example.com/api/subscriptions/webhook');
});

it('activates the plan only from an approved payment re-read from Mercado Pago, exactly once', function (): void {
    [$user, $token] = payingUser();
    Http::fake([
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 9001, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'PIX']],
        ], 201),
    ]);
    $checkout = $this->withToken($token)->postJson('/api/subscriptions/checkout', ['planId' => 'family', 'method' => 'pix'])->json('checkout');

    Http::fake([
        'api.mercadopago.com/v1/payments/9001' => Http::response([
            'id' => 9001, 'status' => 'approved', 'transaction_amount' => 29.9, 'external_reference' => $checkout['id'],
        ]),
    ]);

    // Forged notification: rejected before any call to Mercado Pago
    sendSignedWebhook('payment', '9001', 'wrong-secret')->assertStatus(401);
    $this->postJson('/api/subscriptions/webhook?data.id=9001&type=payment')->assertStatus(401);
    Http::assertNothingSent();

    sendSignedWebhook('payment', '9001')->assertOk()->assertJsonPath('received', true);
    sendSignedWebhook('payment', '9001')->assertOk(); // Mercado Pago retry

    $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();
    expect($subscription->plan_id)->toBe(PlanIdEnum::Family)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->current_period_end->toDateString())->toBe('2026-12-21')
        ->and($user->refresh()->plan_id)->toBe(PlanIdEnum::Family)
        ->and(SubscriptionPayment::query()->count())->toBe(1)
        ->and(PaymentCheckout::query()->findOrFail($checkout['id'])->status->value)->toBe('approved');

    $this->withToken($token)->getJson('/api/subscriptions/current')
        ->assertJsonPath('subscription.planId', 'family')
        ->assertJsonPath('subscription.status', 'active');
});

it('does not grant access when the approved amount is below the plan price', function (): void {
    [$user] = payingUser();
    PaymentCheckout::query()->create([
        'id' => 'chk_under', 'user_id' => $user->id, 'plan_id' => 'clinical', 'billing_cycle' => 'monthly',
        'method' => 'pix', 'amount' => 79.90, 'status' => 'pending', 'gateway_id' => '777',
    ]);
    Http::fake([
        'api.mercadopago.com/v1/payments/777' => Http::response([
            'id' => 777, 'status' => 'approved', 'transaction_amount' => 1.00, 'external_reference' => 'chk_under',
        ]),
    ]);

    sendSignedWebhook('payment', '777')->assertOk();

    expect(Subscription::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(SubscriptionPayment::query()->count())->toBe(0);
});

it('activates and renews a recurring card subscription from its authorized payments', function (): void {
    [$user, $token] = payingUser();
    $checkout = startCardCheckout($token);

    Http::fake([
        'api.mercadopago.com/authorized_payments/501' => Http::response([
            'id' => 501, 'preapproval_id' => 'pre_123', 'transaction_amount' => '29.90',
            'payment' => ['id' => 70001, 'status' => 'approved', 'status_detail' => 'accredited'],
        ]),
        'api.mercadopago.com/authorized_payments/502' => Http::response([
            'id' => 502, 'preapproval_id' => 'pre_123', 'transaction_amount' => '29.90',
            'payment' => ['id' => 70002, 'status' => 'approved', 'status_detail' => 'accredited'],
        ]),
        'api.mercadopago.com/v1/payments/70002' => Http::response([
            'id' => 70002, 'status' => 'approved', 'transaction_amount' => 29.9, 'external_reference' => $checkout['id'],
        ]),
    ]);

    sendSignedWebhook('subscription_authorized_payment', '501')->assertOk();

    $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();
    expect($subscription->auto_renew)->toBeTrue()
        ->and($subscription->gateway_subscription_id)->toBe('pre_123')
        ->and($subscription->payment_method)->toBe('mercado_pago_card_recurring')
        ->and($subscription->current_period_end->toDateString())->toBe('2026-12-21');

    // Next month's charge extends from the end of the paid period, not from "now"
    $this->travelTo(now()->setDate(2026, 12, 21)->setTime(8, 0));
    sendSignedWebhook('subscription_authorized_payment', '502')->assertOk();
    // The same charge also arrives through the generic "payment" topic: applied once
    sendSignedWebhook('payment', '70002')->assertOk();

    expect($subscription->refresh()->current_period_end->toDateString())->toBe('2027-01-21')
        ->and(SubscriptionPayment::query()->pluck('id')->all())->toEqualCanonicalizing(['70001', '70002']);
});

it('cancels the automatic renewal on Mercado Pago and keeps access until the paid period ends', function (): void {
    [$user, $token] = payingUser();
    Subscription::query()->create([
        'id' => 'sub-rec', 'user_id' => $user->id, 'plan_id' => 'family', 'status' => 'active',
        'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addDays(20),
        'auto_renew' => true, 'gateway_subscription_id' => 'pre_123', 'gateway_status' => 'authorized',
    ]);
    Http::fake(['api.mercadopago.com/preapproval/pre_123' => Http::response(['id' => 'pre_123', 'status' => 'canceled'])]);

    $this->withToken($token)->postJson('/api/subscriptions/cancel')
        ->assertOk()
        ->assertJsonPath('subscription.autoRenew', false)
        ->assertJsonPath('subscription.planId', 'family');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.mercadopago.com/preapproval/pre_123'
        && $request['status'] === 'canceled');

    $this->withToken($token)->postJson('/api/subscriptions/cancel')->assertNotFound();
});

it('syncs a subscription canceled on the Mercado Pago side through the preapproval topic', function (): void {
    [$user] = payingUser();
    Subscription::query()->create([
        'id' => 'sub-rec', 'user_id' => $user->id, 'plan_id' => 'family', 'status' => 'active',
        'billing_cycle' => 'monthly', 'current_period_start' => now(), 'current_period_end' => now()->addDays(20),
        'auto_renew' => true, 'gateway_subscription_id' => 'pre_123',
    ]);
    Http::fake(['api.mercadopago.com/preapproval/pre_123' => Http::response(['id' => 'pre_123', 'status' => 'canceled'])]);

    sendSignedWebhook('subscription_preapproval', 'pre_123')->assertOk();

    $subscription = Subscription::query()->findOrFail('sub-rec');
    expect($subscription->auto_renew)->toBeFalse()
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->status)->toBe('active');
});

it('lets the app poll a PIX checkout and returns the subscription once approved', function (): void {
    [$user, $token] = payingUser();
    [, $otherToken] = payingUser();
    PaymentCheckout::query()->create([
        'id' => 'chk_poll', 'user_id' => $user->id, 'plan_id' => 'family', 'billing_cycle' => 'monthly',
        'method' => 'pix', 'amount' => 29.90, 'status' => 'pending', 'gateway_id' => '4242',
        'pix_qr_code' => 'PIX', 'expires_at' => now()->addMinutes(20),
    ]);
    Http::fake([
        'api.mercadopago.com/v1/payments/4242' => Http::sequence()
            ->push(['id' => 4242, 'status' => 'pending', 'transaction_amount' => 29.9, 'external_reference' => 'chk_poll'])
            ->push(['id' => 4242, 'status' => 'approved', 'transaction_amount' => 29.9, 'external_reference' => 'chk_poll']),
    ]);

    $this->withToken($otherToken)->getJson('/api/subscriptions/checkouts/chk_poll')->assertNotFound();

    $this->withToken($token)->getJson('/api/subscriptions/checkouts/chk_poll')
        ->assertOk()->assertJsonPath('checkout.status', 'pending')->assertJsonPath('subscription', null);

    $this->withToken($token)->getJson('/api/subscriptions/checkouts/chk_poll')
        ->assertOk()->assertJsonPath('checkout.status', 'approved')->assertJsonPath('subscription.planId', 'family');
});

it('answers 503 so Mercado Pago retries when its API is temporarily unavailable', function (): void {
    [$user] = payingUser();
    PaymentCheckout::query()->create([
        'id' => 'chk_retry', 'user_id' => $user->id, 'plan_id' => 'family', 'billing_cycle' => 'monthly',
        'method' => 'pix', 'amount' => 29.90, 'status' => 'pending', 'gateway_id' => '555',
    ]);
    Http::fake(['api.mercadopago.com/v1/payments/555' => Http::response(['message' => 'internal'], 500)]);

    sendSignedWebhook('payment', '555')->assertStatus(503);
    expect(Subscription::query()->where('user_id', $user->id)->exists())->toBeFalse();

    // Unknown resources (404) are acknowledged instead of retried forever
    Http::fake(['api.mercadopago.com/v1/payments/556' => Http::response(['message' => 'not found'], 404)]);
    sendSignedWebhook('payment', '556')->assertOk();
});

it('expires plans after the paid period and auto-renewing plans after the grace window', function (): void {
    [$pixUser, $pixToken] = payingUser();
    [$cardUser] = payingUser();
    $pixUser->update(['plan_id' => PlanIdEnum::Family]);
    $cardUser->update(['plan_id' => PlanIdEnum::Family]);
    Subscription::query()->create([
        'id' => 'sub-pix', 'user_id' => $pixUser->id, 'plan_id' => 'family', 'status' => 'active',
        'billing_cycle' => 'monthly', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDay(),
    ]);
    Subscription::query()->create([
        'id' => 'sub-card', 'user_id' => $cardUser->id, 'plan_id' => 'family', 'status' => 'active',
        'billing_cycle' => 'monthly', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDay(),
        'auto_renew' => true, 'gateway_subscription_id' => 'pre_9',
    ]);

    // Before the job runs, the API already treats the lapsed plan as free
    $this->withToken($pixToken)->getJson('/api/subscriptions/current')
        ->assertJsonPath('subscription.planId', 'free')
        ->assertJsonPath('subscription.status', 'expired');

    app(ExpireSubscriptionsAction::class)->execute();

    expect(Subscription::query()->findOrFail('sub-pix')->status)->toBe('expired')
        ->and($pixUser->refresh()->plan_id)->toBe(PlanIdEnum::Free)
        ->and(Subscription::query()->findOrFail('sub-card')->status)->toBe('active');

    $this->travelTo(now()->addDays(11));
    app(ExpireSubscriptionsAction::class)->execute();

    expect(Subscription::query()->findOrFail('sub-card')->status)->toBe('expired')
        ->and($cardUser->refresh()->plan_id)->toBe(PlanIdEnum::Free);
});

it('validates webhook signatures exactly like the official Mercado Pago SDKs', function (): void {
    $ts = '1704908010';
    $hash = hash_hmac('sha256', 'id:123456;request-id:abc-req;ts:1704908010;', 'secret');

    expect(MercadoPagoWebhookSignature::isValid('secret', "ts={$ts},v1={$hash}", 'abc-req', '123456'))->toBeTrue()
        ->and(MercadoPagoWebhookSignature::isValid('secret', " TS = {$ts} , V1 = {$hash} ", 'abc-req', '123456'))->toBeTrue()
        ->and(MercadoPagoWebhookSignature::isValid('other', "ts={$ts},v1={$hash}", 'abc-req', '123456'))->toBeFalse()
        ->and(MercadoPagoWebhookSignature::isValid('secret', "ts={$ts},v1={$hash}", 'other-req', '123456'))->toBeFalse()
        ->and(MercadoPagoWebhookSignature::isValid('secret', "ts={$ts},v1={$hash}", 'abc-req', '999'))->toBeFalse()
        ->and(MercadoPagoWebhookSignature::isValid('secret', "v1={$hash}", 'abc-req', '123456'))->toBeFalse()
        ->and(MercadoPagoWebhookSignature::isValid('secret', null, 'abc-req', '123456'))->toBeFalse()
        ->and(MercadoPagoWebhookSignature::isValid('', "ts={$ts},v1={$hash}", 'abc-req', '123456'))->toBeFalse();

    // request-id is omitted from the manifest when the header is absent
    $noRequestId = hash_hmac('sha256', 'id:123456;ts:1704908010;', 'secret');
    expect(MercadoPagoWebhookSignature::isValid('secret', "ts={$ts},v1={$noRequestId}", null, '123456'))->toBeTrue();

    // alphanumeric ids signed lowercased (older docs) are accepted too
    $lower = hash_hmac('sha256', 'id:abc123;request-id:r;ts:1704908010;', 'secret');
    expect(MercadoPagoWebhookSignature::isValid('secret', "ts={$ts},v1={$lower}", 'r', 'ABC123'))->toBeTrue();
});
