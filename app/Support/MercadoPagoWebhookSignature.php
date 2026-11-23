<?php

namespace App\Support;

/**
 * Validates the `x-signature` header of Mercado Pago webhook notifications.
 *
 * Mirrors the official SDK validators (sdk-php WebhookSignatureValidator,
 * sdk-nodejs utils/webhook, sdk-python webhook/validator):
 *
 *   x-signature: "ts=1704908010,v1=<hex>"
 *   manifest:    "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
 *                (the id / request-id pairs are omitted when empty)
 *   v1:          hex(HMAC-SHA256(manifest, secret)), compared in constant time
 *
 * The SDK code does not lowercase data.id, but older docs say alphanumeric ids
 * are signed lowercased, so both forms are accepted (still requires the secret).
 */
final class MercadoPagoWebhookSignature
{
    public static function isValid(string $secret, ?string $signatureHeader, ?string $requestId, string $dataId): bool
    {
        if ($secret === '' || $signatureHeader === null || trim($signatureHeader) === '' || trim($dataId) === '') {
            return false;
        }

        $ts = null;
        $hash = null;
        foreach (explode(',', $signatureHeader) as $part) {
            $pieces = explode('=', $part, 2);
            if (count($pieces) !== 2) {
                continue;
            }
            $key = strtolower(trim($pieces[0]));
            $value = trim($pieces[1]);
            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $hash = strtolower($value);
            }
        }

        if ($ts === null || $hash === null || preg_match('/^\d+$/', $ts) !== 1) {
            return false;
        }

        $dataId = trim($dataId);
        foreach (array_unique([$dataId, strtolower($dataId)]) as $candidateId) {
            $computed = hash_hmac('sha256', self::manifest($candidateId, $requestId, $ts), $secret);
            if (hash_equals($computed, $hash)) {
                return true;
            }
        }

        return false;
    }

    public static function manifest(string $dataId, ?string $requestId, string $ts): string
    {
        $parts = [];
        if (trim($dataId) !== '') {
            $parts[] = 'id:'.trim($dataId);
        }
        if ($requestId !== null && trim($requestId) !== '') {
            $parts[] = 'request-id:'.trim($requestId);
        }
        $parts[] = 'ts:'.$ts;

        return implode(';', $parts).';';
    }
}
