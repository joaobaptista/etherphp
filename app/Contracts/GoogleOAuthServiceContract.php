<?php

namespace App\Contracts;

interface GoogleOAuthServiceContract
{
    public function isConfigured(): bool;

    public function buildLoginUrl(string $origin): string;

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchUserInfo(string $accessToken): array;

    public function cacheAvatar(string $remoteUrl, string $userId): string;

    public function isGoogleManagedAvatar(?string $avatarUrl): bool;
}
