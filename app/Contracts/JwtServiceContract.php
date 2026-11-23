<?php

namespace App\Contracts;

use App\Models\User;

interface JwtServiceContract
{
    public function issueForUser(User $user, ?int $ttlSeconds = null): string;

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array;

    /**
     * @param  array<string, mixed>  $claims
     */
    public function signState(array $claims, int $ttlSeconds = 600, ?string $audience = null): string;

    /**
     * @return array<string, mixed>|null
     */
    public function verifyState(string $token, ?string $expectedAudience = null): ?array;
}
