<?php

namespace App\Contracts;

interface GooglePhotosPickerContract
{
    public function isConfigured(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getConnectionStatus(string $userId): array;

    /**
     * @return array{authorizationUrl: string, url: string}
     */
    public function buildAuthorizationUrl(string $userId, string $origin): array;

    /**
     * @return array{success: bool, email: string}
     */
    public function handleOAuthCallback(string $code, string $state): array;

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $userId, string $capsuleId): array;

    /**
     * @return array<string, mixed>
     */
    public function pollSession(string $userId, string $capsuleId, string $sessionId): array;

    /**
     * Starts the import of the selected photos in the background (queue) and returns the
     * session progress right away. Calling it again while importing just returns progress.
     *
     * @return array<string, mixed>
     */
    public function importSession(string $userId, string $capsuleId, string $sessionId): array;

    /**
     * Imports a picker session (runs in ImportGooglePhotosSessionJob). Resumable: items
     * already imported are skipped. Only photos are imported; videos are counted and skipped.
     */
    public function runImport(int $pickerSessionId): void;

    public function disconnect(string $userId): void;
}
