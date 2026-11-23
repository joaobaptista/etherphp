<?php

namespace App\Services;

use App\Contracts\PaymentGatewayContract;
use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentCheckout;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago REST client (https://api.mercadopago.com).
 *
 * - Recurring card plans: POST/GET/PUT /preapproval (subscription without plan,
 *   created as `pending`; the payer adds the card on Mercado Pago's `init_point`).
 * - One-off PIX: POST /v1/payments with `payment_method_id = pix`.
 * - Notifications are never trusted: callers re-read the resource through
 *   getPayment()/getPreapproval()/getAuthorizedPayment().
 */
class MercadoPagoPaymentGateway implements PaymentGatewayContract
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array
    {
        /** @var array<string, array<string, mixed>> $plans */
        $plans = config('ethernity.plans', []);

        return $plans;
    }

    public function isConfigured(): bool
    {
        return $this->accessToken() !== '';
    }

    public function createRecurringSubscription(PaymentCheckout $checkout, User $user, string $reason): array
    {
        $backUrl = (string) config('ethernity.mercado_pago.back_url', '');
        $separator = str_contains($backUrl, '?') ? '&' : '?';

        $body = [
            'reason' => mb_substr($reason, 0, 250),
            'external_reference' => $checkout->id,
            'payer_email' => $user->email,
            'auto_recurring' => [
                'frequency' => $checkout->billing_cycle === 'yearly' ? 12 : 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $checkout->amount,
                'currency_id' => 'BRL',
            ],
            'back_url' => $backUrl.$separator.'checkout='.urlencode($checkout->id),
            'status' => 'pending',
        ];

        $data = $this->send('post', '/preapproval', $body, 'criar a assinatura');

        $id = (string) ($data['id'] ?? '');
        $initPoint = (string) ($data['init_point'] ?? '');
        if ($id === '' || $initPoint === '') {
            throw new PaymentGatewayException('Mercado Pago não retornou o link da assinatura.');
        }

        return ['id' => $id, 'init_point' => $initPoint, 'status' => (string) ($data['status'] ?? 'pending')];
    }

    public function createPixPayment(PaymentCheckout $checkout, User $user, string $description): array
    {
        $minutes = max(30, (int) config('ethernity.mercado_pago.pix_expiration_minutes', 30));
        $expiresAt = now()->addMinutes($minutes);

        $body = [
            'transaction_amount' => (float) $checkout->amount,
            'description' => mb_substr($description, 0, 250),
            'payment_method_id' => 'pix',
            'external_reference' => $checkout->id,
            'date_of_expiration' => $expiresAt->format('Y-m-d\TH:i:s.vP'),
            'payer' => [
                'email' => $user->email,
                'first_name' => mb_substr(explode(' ', trim($user->name))[0] ?: $user->name, 0, 100),
            ],
        ];

        $notificationUrl = $this->notificationUrl();
        if ($notificationUrl !== null) {
            $body['notification_url'] = $notificationUrl;
        }

        // The checkout id makes retries of the same checkout return the same PIX charge.
        $data = $this->send('post', '/v1/payments', $body, 'gerar o PIX', ['X-Idempotency-Key' => $checkout->id]);

        /** @var array<string, mixed> $transaction */
        $transaction = data_get($data, 'point_of_interaction.transaction_data', []);
        $qrCode = (string) ($transaction['qr_code'] ?? '');
        if (! isset($data['id']) || $qrCode === '') {
            throw new PaymentGatewayException('Mercado Pago não retornou o código PIX.');
        }

        return [
            'id' => (string) $data['id'],
            'status' => (string) ($data['status'] ?? 'pending'),
            'qr_code' => $qrCode,
            'qr_code_base64' => isset($transaction['qr_code_base64']) ? (string) $transaction['qr_code_base64'] : null,
            'ticket_url' => isset($transaction['ticket_url']) ? (string) $transaction['ticket_url'] : null,
            'expires_at' => isset($data['date_of_expiration'])
                ? (string) $data['date_of_expiration']
                : $expiresAt->toIso8601String(),
        ];
    }

    public function getPayment(string $paymentId): array
    {
        $data = $this->send('get', '/v1/payments/'.rawurlencode($paymentId), null, 'consultar o pagamento');

        return [
            'id' => (string) ($data['id'] ?? $paymentId),
            'status' => (string) ($data['status'] ?? ''),
            'amount' => (float) ($data['transaction_amount'] ?? 0),
            'external_reference' => isset($data['external_reference']) ? (string) $data['external_reference'] : null,
        ];
    }

    public function getPreapproval(string $preapprovalId): array
    {
        $data = $this->send('get', '/preapproval/'.rawurlencode($preapprovalId), null, 'consultar a assinatura');

        return [
            'id' => (string) ($data['id'] ?? $preapprovalId),
            'status' => (string) ($data['status'] ?? ''),
            'external_reference' => isset($data['external_reference']) ? (string) $data['external_reference'] : null,
            'next_payment_date' => isset($data['next_payment_date']) ? (string) $data['next_payment_date'] : null,
        ];
    }

    public function getAuthorizedPayment(string $authorizedPaymentId): array
    {
        $data = $this->send('get', '/authorized_payments/'.rawurlencode($authorizedPaymentId), null, 'consultar a cobrança recorrente');

        $paymentId = data_get($data, 'payment.id');
        $paymentStatus = data_get($data, 'payment.status');

        return [
            'id' => (string) ($data['id'] ?? $authorizedPaymentId),
            'preapproval_id' => isset($data['preapproval_id']) ? (string) $data['preapproval_id'] : null,
            'external_reference' => isset($data['external_reference']) ? (string) $data['external_reference'] : null,
            'payment_id' => is_scalar($paymentId) ? (string) $paymentId : null,
            'payment_status' => is_scalar($paymentStatus) ? (string) $paymentStatus : null,
            'amount' => (float) ($data['transaction_amount'] ?? 0),
        ];
    }

    public function cancelPreapproval(string $preapprovalId): void
    {
        // Mercado Pago spells the value with a single "l" ("canceled").
        $this->send('put', '/preapproval/'.rawurlencode($preapprovalId), ['status' => 'canceled'], 'cancelar a assinatura');
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body, string $action, array $headers = []): array
    {
        if (! $this->isConfigured()) {
            throw new PaymentGatewayException('Pagamentos online não estão configurados (MERCADO_PAGO_ACCESS_TOKEN).');
        }

        try {
            $request = $this->client()->withHeaders($headers);
            /** @var Response $response */
            $response = $body === null ? $request->{$method}($path) : $request->{$method}($path, $body);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException("Não foi possível falar com o Mercado Pago para {$action}.", 0, $e);
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->json('error') ?? 'erro desconhecido');

            throw new PaymentGatewayException(
                "Mercado Pago recusou {$action} (HTTP {$response->status()}): {$message}",
                $response->status(),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        return $data;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('ethernity.mercado_pago.api_base', 'https://api.mercadopago.com'))
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(max(5, (int) config('ethernity.mercado_pago.http_timeout_seconds', 15)));
    }

    private function accessToken(): string
    {
        return trim((string) config('ethernity.mercado_pago.access_token', ''));
    }

    /** Mercado Pago only accepts a public https notification_url (under 500 chars). */
    private function notificationUrl(): ?string
    {
        $url = trim((string) config('ethernity.mercado_pago.notification_url', ''));

        return str_starts_with($url, 'https://') && strlen($url) < 500 ? $url : null;
    }
}
