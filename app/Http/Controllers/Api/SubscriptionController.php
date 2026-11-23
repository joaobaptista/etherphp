<?php

namespace App\Http\Controllers\Api;

use App\Actions\Subscriptions\ActivateSubscriptionAction;
use App\Actions\Subscriptions\CancelAutoRenewAction;
use App\Actions\Subscriptions\GetCurrentSubscriptionAction;
use App\Actions\Subscriptions\HandleMercadoPagoNotificationAction;
use App\Actions\Subscriptions\StartCheckoutAction;
use App\Actions\Subscriptions\SyncCheckoutAction;
use App\Contracts\PaymentGatewayContract;
use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartCheckoutRequest;
use App\Models\PaymentCheckout;
use App\Models\User;
use App\Support\MercadoPagoWebhookSignature;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Assinaturas', 'Planos e pagamentos pelo Mercado Pago (cartão recorrente e PIX).', weight: 100)]
class SubscriptionController extends Controller
{
    /**
     * Listar planos
     */
    public function plans(PaymentGatewayContract $gateway): JsonResponse
    {
        $publicPlans = array_values(array_filter(
            $gateway->plans(),
            static fn (array $plan): bool => ($plan['id'] ?? '') !== 'infinity',
        ));

        return response()->json([
            'plans' => $publicPlans,
            'currency' => 'BRL',
            'supportedPaymentMethods' => ['credit_card_recurring', 'pix'],
            'paymentsEnabled' => $gateway->isConfigured(),
            'simulationEnabled' => (bool) config('ethernity.security.payment_simulation_enabled', false),
        ]);
    }

    /**
     * Assinatura atual
     */
    public function current(Request $request, GetCurrentSubscriptionAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($action->execute($user));
    }

    /**
     * Iniciar pagamento
     *
     * Cartão: cria uma assinatura recorrente no Mercado Pago e devolve o link do checkout (`initPoint`). PIX: cria a cobrança do período e devolve o QR Code.
     */
    public function startCheckout(StartCheckoutRequest $request, StartCheckoutAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $checkout = $action->execute(
                $user,
                (string) $request->validated('planId'),
                (string) $request->input('cycle', 'monthly'),
                CheckoutMethodEnum::from((string) $request->validated('method')),
            );
        } catch (PaymentGatewayException $e) {
            Log::error('Mercado Pago checkout failed.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['detail' => 'Não foi possível iniciar o pagamento no Mercado Pago. Tente novamente em instantes.'], 502);
        }

        return response()->json(['checkout' => $this->presentCheckout($checkout)], 201);
    }

    /**
     * Situação de um pagamento
     *
     * Consulta o Mercado Pago e ativa o plano quando o pagamento for aprovado.
     */
    public function showCheckout(
        Request $request,
        string $id,
        SyncCheckoutAction $sync,
        GetCurrentSubscriptionAction $current,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $checkout = PaymentCheckout::query()->where('user_id', $user->id)->find($id);
        if ($checkout === null) {
            return response()->json(['detail' => 'Pagamento não encontrado.'], 404);
        }

        $checkout = $sync->execute($checkout);

        return response()->json([
            'checkout' => $this->presentCheckout($checkout),
            'subscription' => $checkout->status === CheckoutStatusEnum::Approved
                ? $current->execute($user)['subscription']
                : null,
        ]);
    }

    /**
     * Cancelar a renovação automática
     *
     * O plano continua ativo até o fim do período pago.
     */
    public function cancelAutoRenew(Request $request, CancelAutoRenewAction $action, GetCurrentSubscriptionAction $current): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $action->execute($user);
        } catch (PaymentGatewayException $e) {
            Log::error('Mercado Pago cancellation failed.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['detail' => 'Não foi possível cancelar a renovação no Mercado Pago. Tente novamente em instantes.'], 502);
        }

        return response()->json([
            'message' => 'Renovação automática cancelada. Seu plano continua ativo até o fim do período pago.',
            'subscription' => $current->execute($user)['subscription'],
        ]);
    }

    /**
     * Simular pagamento (só desenvolvimento)
     *
     * Desligado em produção: responde 403.
     */
    #[BodyParameter('planId', 'Plano a ativar.', required: true, type: 'string')]
    #[BodyParameter('cycle', 'Ciclo de cobrança.', type: "'monthly'|'yearly'", default: 'monthly')]
    public function simulatePayment(Request $request, ActivateSubscriptionAction $action): JsonResponse
    {
        if (! (bool) config('ethernity.security.payment_simulation_enabled', false)) {
            return response()->json(['detail' => 'Pagamento simulado desabilitado neste ambiente.'], 403);
        }

        /** @var User $user */
        $user = $request->user();
        $planId = (string) $request->input('planId', '');
        $cycle = (string) $request->input('cycle', 'monthly');
        $paymentId = (string) $request->input('paymentId', 'mp_'.time());

        $subscription = $action->execute($user, $planId, $cycle, 'mercado_pago_pix', [
            'lastPaymentId' => $paymentId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plano "'.$subscription['planName'].'" ativado com sucesso!',
            'subscription' => $subscription,
        ]);
    }

    /**
     * Webhook do Mercado Pago
     *
     * Rota pública chamada pelo Mercado Pago. A assinatura `x-signature` é conferida e o estado do pagamento é sempre consultado de novo na API do Mercado Pago.
     *
     * Mercado Pago notification endpoint (public). The body is only used to learn
     * which resource changed; its state is always re-read from the Mercado Pago API.
     */
    public function webhook(Request $request, HandleMercadoPagoNotificationAction $action): JsonResponse
    {
        // Mercado Pago sends ?data.id=…&type=…; PHP exposes "data.id" as "data_id".
        $queryId = $request->query('data_id') ?? $request->query('id');
        $bodyId = $request->input('data.id');
        $resourceId = trim((string) (is_scalar($queryId) ? $queryId : (is_scalar($bodyId) ? $bodyId : '')));
        $typeValue = $request->query('type') ?? $request->query('topic') ?? $request->input('type') ?? $request->input('topic');
        $type = is_scalar($typeValue) ? (string) $typeValue : '';

        $secret = (string) config('ethernity.mercado_pago.webhook_secret', '');
        if ($secret !== '') {
            $valid = MercadoPagoWebhookSignature::isValid(
                $secret,
                $request->header('x-signature'),
                $request->header('x-request-id'),
                $resourceId,
            );
            if (! $valid) {
                Log::warning('Rejected Mercado Pago webhook with invalid signature.', ['type' => $type, 'id' => $resourceId]);

                return response()->json(['detail' => 'Assinatura inválida.'], 401);
            }
        }

        try {
            $action->execute($type, $resourceId);
        } catch (PaymentGatewayException $e) {
            Log::error('Mercado Pago webhook processing failed; it will be retried.', [
                'type' => $type,
                'id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['detail' => 'Falha temporária ao processar a notificação.'], 503);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCheckout(PaymentCheckout $checkout): array
    {
        return [
            'id' => $checkout->id,
            'method' => $checkout->method->value,
            'status' => $checkout->status->value,
            'gatewayStatus' => $checkout->gateway_status,
            'planId' => $checkout->plan_id,
            'cycle' => $checkout->billing_cycle,
            'amount' => (float) $checkout->amount,
            'initPoint' => $checkout->init_point,
            'pix' => $checkout->method === CheckoutMethodEnum::Pix ? [
                'qrCode' => $checkout->pix_qr_code,
                'qrCodeBase64' => $checkout->pix_qr_code_base64,
                'ticketUrl' => $checkout->pix_ticket_url,
                'expiresAt' => $checkout->expires_at?->toIso8601String(),
            ] : null,
        ];
    }
}
