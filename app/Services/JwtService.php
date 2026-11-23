<?php

namespace App\Services;

use App\Contracts\JwtServiceContract;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

class JwtService implements JwtServiceContract
{
    public function issueForUser(User $user, ?int $ttlSeconds = null): string
    {
        $now = time();
        $ttl = $ttlSeconds ?? (int) config('ethernity.jwt.ttl_seconds', 86400);

        $payload = [
            'sub' => $user->email,
            'uid' => $user->id,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $this->secret(), $this->algorithm());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        if (trim($token) === '') {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->secret(), $this->algorithm()));

            /** @var array<string, mixed> $claims */
            $claims = (array) $decoded;

            return $claims;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function signState(array $claims, int $ttlSeconds = 600, ?string $audience = null): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        if ($audience !== null) {
            $payload['aud'] = $audience;
        }

        return JWT::encode($payload, $this->secret(), $this->algorithm());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyState(string $token, ?string $expectedAudience = null): ?array
    {
        $claims = $this->verify($token);
        if ($claims === null) {
            return null;
        }

        if ($expectedAudience !== null && ($claims['aud'] ?? null) !== $expectedAudience) {
            return null;
        }

        return $claims;
    }

    private function secret(): string
    {
        $secret = (string) config('ethernity.jwt.secret', '');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured.');
        }

        return $secret;
    }

    private function algorithm(): string
    {
        return (string) config('ethernity.jwt.algorithm', 'HS256');
    }
}
