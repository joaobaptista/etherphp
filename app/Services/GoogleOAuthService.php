<?php

namespace App\Services;

use App\Contracts\GoogleOAuthServiceContract;
use App\Contracts\JwtServiceContract;
use App\Contracts\VaultStorageServiceContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GoogleOAuthService implements GoogleOAuthServiceContract
{
    public function __construct(
        protected JwtServiceContract $jwt,
        protected VaultStorageServiceContract $storage,
    ) {}

    public function isConfigured(): bool
    {
        return (string) config('ethernity.google.client_id', '') !== ''
            && (string) config('ethernity.google.client_secret', '') !== ''
            && (string) config('ethernity.google.redirect_uri', '') !== '';
    }

    public function buildLoginUrl(string $origin): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google OAuth não está configurado no servidor.');
        }

        $safeOrigin = $this->resolveAllowedOrigin($origin);
        $stateToken = $this->jwt->signState([
            'action' => 'auth',
            'origin' => $safeOrigin,
            'nonce' => bin2hex(random_bytes(16)),
        ], (int) config('ethernity.google.state_ttl_seconds', 600), 'google-auth-state');

        $query = http_build_query([
            'client_id' => (string) config('ethernity.google.client_id'),
            'redirect_uri' => (string) config('ethernity.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'prompt' => 'select_account',
            'state' => $stateToken,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => (string) config('ethernity.google.client_id'),
            'client_secret' => (string) config('ethernity.google.client_secret'),
            'redirect_uri' => (string) config('ethernity.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        if (! $response->successful()) {
            $message = (string) ($data['error_description'] ?? $data['error'] ?? 'Falha ao autenticar com o Google.');
            throw new RuntimeException($message);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível obter dados do perfil Google.');
        }

        /** @var array<string, mixed> $profile */
        $profile = $response->json() ?? [];

        return $profile;
    }

    public function cacheAvatar(string $remoteUrl, string $userId): string
    {
        if (! str_starts_with($remoteUrl, 'http')) {
            return $remoteUrl;
        }

        try {
            $res = Http::timeout(8)->get($remoteUrl);
            if (! $res->successful()) {
                return $remoteUrl;
            }

            $contentType = strtolower((string) $res->header('Content-Type'));
            $ext = match (true) {
                str_contains($contentType, 'png') => '.png',
                str_contains($contentType, 'webp') => '.webp',
                default => '.jpg',
            };

            $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', $userId) ?? 'user';

            return $this->storage->putUploadFile("avatar-google-{$safeId}{$ext}", $res->body());
        } catch (Throwable) {
            return $remoteUrl;
        }
    }

    public function isGoogleManagedAvatar(?string $avatarUrl): bool
    {
        if ($avatarUrl === null || trim($avatarUrl) === '') {
            return true;
        }

        return str_starts_with($avatarUrl, '/uploads/avatar-google-')
            || preg_match('#^https://[^/]*googleusercontent\.com/#', $avatarUrl) === 1;
    }

    private function resolveAllowedOrigin(string $candidate): string
    {
        $clean = rtrim(trim($candidate), '/');
        /** @var list<string> $allowed */
        $allowed = config('ethernity.allowed_origins', []);

        if ($clean !== '' && in_array($clean, $allowed, true)) {
            return $clean;
        }

        return (string) config('ethernity.frontend_url', config('app.url'));
    }
}
