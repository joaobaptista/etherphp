<?php

namespace App\Contracts;

use App\Models\PaymentCheckout;
use App\Models\User;

interface PaymentGatewayContract
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array;

    /** Whether real payments can be processed (access token configured). */
    public function isConfigured(): bool;

    /**
     * Creates a recurring card subscription (preapproval, status pending) that the
     * payer completes on Mercado Pago's hosted page.
     *
     * @return array{id: string, init_point: string, status: string}
     */
    public function createRecurringSubscription(PaymentCheckout $checkout, User $user, string $reason): array;

    /**
     * Creates a one-off PIX payment.
     *
     * @return array{id: string, status: string, qr_code: string, qr_code_base64: string|null, ticket_url: string|null, expires_at: string}
     */
    public function createPixPayment(PaymentCheckout $checkout, User $user, string $description): array;

    /**
     * @return array{id: string, status: string, amount: float, external_reference: string|null}
     */
    public function getPayment(string $paymentId): array;

    /**
     * @return array{id: string, status: string, external_reference: string|null, next_payment_date: string|null}
     */
    public function getPreapproval(string $preapprovalId): array;

    /**
     * Recurring charge of a subscription.
     *
     * @return array{id: string, preapproval_id: string|null, external_reference: string|null, payment_id: string|null, payment_status: string|null, amount: float}
     */
    public function getAuthorizedPayment(string $authorizedPaymentId): array;

    public function cancelPreapproval(string $preapprovalId): void;
}
