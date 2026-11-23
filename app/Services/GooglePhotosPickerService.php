<?php

namespace App\Services;

use App\Contracts\GooglePhotosPickerContract;
use App\Contracts\ImageProcessorContract;
use App\Contracts\JwtServiceContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use App\Enums\EncryptionModeEnum;
use App\Enums\IntegrationStatusEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Enums\PickerSessionStatusEnum;
use App\Jobs\ImportGooglePhotosSessionJob;
use App\Models\GooglePhotosPickerSession;
use App\Models\IntegrationConnection;
use App\Models\Memory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GooglePhotosPickerService implements GooglePhotosPickerContract
{
    private const PICKER_BASE = 'https://photospicker.googleapis.com/v1';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function __construct(
        protected JwtServiceContract $jwt,
        protected VaultCryptoServiceContract $crypto,
        protected VaultStorageServiceContract $storage,
        protected ImageProcessorContract $images,
    ) {}

    public function isConfigured(): bool
    {
        $encKey = base64_decode((string) config('ethernity.google.token_encryption_key', ''), true);

        return (string) config('ethernity.google.client_id', '') !== ''
            && (string) config('ethernity.google.client_secret', '') !== ''
            && (string) config('ethernity.google.redirect_uri', '') !== ''
            && $encKey !== false
            && strlen($encKey) === 32;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConnectionStatus(string $userId): array
    {
        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'connected' => false,
                'provider' => 'google_photos',
                'displayName' => null,
                'lastSyncedAt' => null,
            ];
        }

        $connection = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('provider', 'google_photos')
            ->where('status', IntegrationStatusEnum::Active->value)
            ->first();

        if ($connection === null) {
            return [
                'configured' => true,
                'connected' => false,
                'provider' => 'google_photos',
                'displayName' => null,
                'lastSyncedAt' => null,
            ];
        }

        return [
            'configured' => true,
            'connected' => true,
            'provider' => 'google_photos',
            'displayName' => $connection->display_name,
            'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
            'externalAccountId' => $connection->external_account_id,
        ];
    }

    /**
     * @return array{authorizationUrl: string, url: string}
     */
    public function buildAuthorizationUrl(string $userId, string $origin): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Photos Picker integration is not configured on this server.');
        }

        $safeOrigin = $this->resolveAllowedOrigin($origin);

        $stateToken = $this->jwt->signState([
            'userId' => $userId,
            'action' => 'integrate_photos',
            'origin' => $safeOrigin,
            'nonce' => bin2hex(random_bytes(16)),
        ], (int) config('ethernity.google.state_ttl_seconds', 600));

        $query = http_build_query([
            'client_id' => (string) config('ethernity.google.client_id'),
            'redirect_uri' => (string) config('ethernity.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly openid email profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $stateToken,
        ]);

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;

        return [
            'authorizationUrl' => $url,
            'url' => $url,
        ];
    }

    /**
     * @return array{success: bool, email: string}
     */
    public function handleOAuthCallback(string $code, string $state): array
    {
        $payload = $this->jwt->verifyState($state);
        if ($payload === null || ! isset($payload['userId'])) {
            throw new RuntimeException('O estado da autorização expirou ou é inválido.');
        }

        $userId = (string) $payload['userId'];

        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => (string) config('ethernity.google.client_id'),
            'client_secret' => (string) config('ethernity.google.client_secret'),
            'redirect_uri' => (string) config('ethernity.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        /** @var array<string, mixed> $tokenData */
        $tokenData = $tokenResponse->json() ?? [];
        if (! $tokenResponse->successful()) {
            throw new RuntimeException((string) ($tokenData['error_description'] ?? $tokenData['error'] ?? 'Falha ao trocar código por tokens.'));
        }

        $accessToken = (string) ($tokenData['access_token'] ?? '');

        // The connection is stored only with the real Google account: never a placeholder
        // e-mail/id (that is how every user ended up "connected" as the same account, issue #6).
        try {
            $userInfo = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível confirmar a conta Google. Tente conectar novamente.', 0, $e);
        }

        /** @var array<string, mixed> $profile */
        $profile = $userInfo->successful() ? ($userInfo->json() ?? []) : [];
        if (trim((string) ($profile['sub'] ?? '')) === '' || trim((string) ($profile['email'] ?? '')) === '') {
            throw new RuntimeException('O Google não informou a conta conectada. Tente conectar novamente.');
        }

        $credentials = [
            'accessToken' => $accessToken,
            'refreshToken' => $tokenData['refresh_token'] ?? null,
            'expiresAt' => (time() + (int) ($tokenData['expires_in'] ?? 3600)) * 1000,
            'scope' => (string) ($tokenData['scope'] ?? ''),
        ];

        $encKey = (string) config('ethernity.google.token_encryption_key', '');
        $encryptedCredentials = $this->crypto->encryptJson($credentials, $encKey);

        $externalAccountId = (string) $profile['sub'];
        $displayName = (string) $profile['email'];

        IntegrationConnection::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'provider' => 'google_photos',
                'external_account_id' => $externalAccountId,
            ],
            [
                'display_name' => $displayName,
                'scopes' => array_values(array_filter(explode(' ', $credentials['scope']))),
                'credentials_encrypted' => $encryptedCredentials,
                'status' => IntegrationStatusEnum::Active,
                'last_synced_at' => now(),
                'last_error' => null,
            ],
        );

        return ['success' => true, 'email' => (string) $profile['email']];
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $userId, string $capsuleId): array
    {
        // Not connected / access revoked: getValidAccessToken throws the user-facing reason
        $accessToken = $this->getValidAccessToken($userId);

        // The picker itself enforces the per-session limit, so nothing chosen is silently dropped
        $response = Http::withToken($accessToken)->post(self::PICKER_BASE.'/sessions', [
            'pickingConfig' => ['maxItemCount' => (string) $this->maxItemsPerSession()],
        ]);
        /** @var array<string, mixed> $sessionData */
        $sessionData = $response->json() ?? [];
        if (! $response->successful()) {
            $errorMessage = is_array($sessionData['error'] ?? null)
                ? (string) ($sessionData['error']['message'] ?? 'Falha ao criar sessão no Google Photos Picker API.')
                : 'Falha ao criar sessão no Google Photos Picker API.';
            throw new RuntimeException($errorMessage);
        }

        $session = GooglePhotosPickerSession::query()->create([
            'user_id' => $userId,
            'capsule_id' => $capsuleId !== '' ? $capsuleId : 'capsule-default',
            'google_session_id' => (string) $sessionData['id'],
            'picker_uri' => (string) ($sessionData['pickerUri'] ?? ''),
            'status' => PickerSessionStatusEnum::Picking,
            'metadata' => $sessionData,
            'expires_at' => isset($sessionData['expireTime']) ? Carbon::parse((string) $sessionData['expireTime']) : null,
        ]);

        return [
            'id' => $session->id,
            'sessionId' => $session->google_session_id,
            'pickerUri' => $session->picker_uri,
            'pollingConfig' => $sessionData['pollingConfig'] ?? ['pollInterval' => '3s'],
            'mediaItemsSet' => (bool) ($sessionData['mediaItemsSet'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pollSession(string $userId, string $capsuleId, string $sessionId): array
    {
        $session = $this->findSession($userId, $sessionId);
        if ($session === null) {
            return [
                'id' => $sessionId,
                'sessionId' => $sessionId,
                'status' => PickerSessionStatusEnum::Picking->value,
                'mediaItemsSet' => false,
                'itemCount' => 0,
                'pollingConfig' => ['pollInterval' => '3s'],
            ];
        }

        // Selection done (or importing / imported / failed): report state and import progress
        if (in_array($session->status, [
            PickerSessionStatusEnum::SelectionReady,
            PickerSessionStatusEnum::Importing,
            PickerSessionStatusEnum::Completed,
            PickerSessionStatusEnum::Error,
        ], true)) {
            return $this->presentSession($session, true);
        }

        $accessToken = $this->getValidAccessToken($userId);
        $response = Http::withToken($accessToken)->get(self::PICKER_BASE.'/sessions/'.urlencode($session->google_session_id));
        /** @var array<string, mixed> $googleData */
        $googleData = $response->json() ?? [];
        if (! $response->successful()) {
            throw new RuntimeException('Falha ao verificar status da sessão no Google Fotos.');
        }

        $mediaItemsSet = (bool) ($googleData['mediaItemsSet'] ?? false);
        $session->update($mediaItemsSet
            ? ['status' => PickerSessionStatusEnum::SelectionReady, 'selection_ready_at' => now(), 'last_polled_at' => now()]
            : ['last_polled_at' => now()]);

        $payload = $this->presentSession($session->refresh(), $mediaItemsSet);
        $payload['pollingConfig'] = $googleData['pollingConfig'] ?? ['pollInterval' => '3s'];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function importSession(string $userId, string $capsuleId, string $sessionId): array
    {
        $session = $this->findSession($userId, $sessionId);
        if ($session === null) {
            return ['success' => true, 'status' => PickerSessionStatusEnum::Completed->value, 'importedCount' => 0, 'items' => []];
        }

        // Already importing or done: just report progress (the UI may retry the request)
        if (in_array($session->status, [PickerSessionStatusEnum::Importing, PickerSessionStatusEnum::Completed], true)) {
            return ['success' => true] + $this->presentSession($session, true);
        }

        // Fail fast (before queueing) if the Google connection is gone
        $this->getValidAccessToken($userId);

        $session->update([
            'capsule_id' => $capsuleId !== '' && $capsuleId !== 'capsule-default' ? $capsuleId : $session->capsule_id,
            'status' => PickerSessionStatusEnum::Importing,
            'import_started_at' => now(),
            'last_error' => null,
        ]);

        ImportGooglePhotosSessionJob::dispatch($session->id);

        return ['success' => true] + $this->presentSession($session->refresh(), true);
    }

    public function runImport(int $pickerSessionId): void
    {
        $session = GooglePhotosPickerSession::query()->find($pickerSessionId);
        if ($session === null || $session->status === PickerSessionStatusEnum::Completed) {
            return;
        }

        $userId = $session->user_id;
        $meta = is_array($session->metadata) ? $session->metadata : [];
        $progress = is_array($meta['import'] ?? null) ? $meta['import'] : [];
        $items = is_array($progress['items'] ?? null) ? $progress['items'] : [];
        $doneIds = array_flip(array_map('strval', $session->imported_item_ids ?? []));
        $skipped = (int) ($progress['skipped'] ?? 0);
        $skippedVideos = (int) ($progress['skippedVideos'] ?? 0);
        $alreadyInApp = (int) ($progress['alreadyInApp'] ?? 0);
        $selected = 0;
        $maxFileBytes = (int) config('ethernity.google.max_file_size_bytes', 50 * 1024 * 1024);

        $saveProgress = function () use ($session, &$meta, &$items, &$skipped, &$skippedVideos, &$alreadyInApp, &$doneIds, &$selected): void {
            $meta['import'] = ['skipped' => $skipped, 'skippedVideos' => $skippedVideos, 'alreadyInApp' => $alreadyInApp, 'items' => $items];
            $session->update([
                'metadata' => $meta,
                'selected_count' => max($selected, $session->selected_count),
                'imported_count' => count($items),
                'imported_item_ids' => array_keys($doneIds),
            ]);
        };

        try {
            $accessToken = $this->getValidAccessToken($userId);
            $pageToken = null;

            do {
                $query = ['sessionId' => $session->google_session_id, 'pageSize' => 100];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }
                $listResponse = Http::withToken($accessToken)->timeout(30)->get(self::PICKER_BASE.'/mediaItems', $query);
                if (! $listResponse->successful()) {
                    throw new RuntimeException('Falha ao listar itens selecionados no Google Fotos.');
                }

                /** @var array<string, mixed> $listData */
                $listData = $listResponse->json() ?? [];
                /** @var list<array<string, mixed>> $mediaItems */
                $mediaItems = is_array($listData['mediaItems'] ?? null) ? $listData['mediaItems'] : [];
                $pageToken = isset($listData['nextPageToken']) && $listData['nextPageToken'] !== '' ? (string) $listData['nextPageToken'] : null;

                foreach ($mediaItems as $item) {
                    $selected++;
                    $itemId = (string) ($item['id'] ?? '');
                    if ($itemId !== '' && isset($doneIds[$itemId])) {
                        continue; // resumed import: already in the vault
                    }

                    $mediaFile = is_array($item['mediaFile'] ?? null) ? $item['mediaFile'] : [];
                    $mime = strtolower((string) ($mediaFile['mimeType'] ?? ''));
                    $isVideo = ($item['type'] ?? '') === 'VIDEO' || str_starts_with($mime, 'video/');
                    if ($isVideo) {
                        // Only photos are imported (product decision); the UI tells the user how many were skipped
                        $skippedVideos++;
                        $doneIds[$itemId !== '' ? $itemId : 'video-'.$selected] = true;
                        $saveProgress();

                        continue;
                    }

                    // Every photo is unique in the app: a Google item imported before (in any
                    // session) is not downloaded and stored again
                    if ($itemId !== '' && Memory::query()
                        ->where('author_id', $userId)
                        ->where('source', MemorySourceEnum::GooglePhotos->value)
                        ->where('provider_metadata->id', $itemId)
                        ->exists()) {
                        $alreadyInApp++;
                        $doneIds[$itemId] = true;
                        $saveProgress();

                        continue;
                    }

                    $imported = $this->importPhoto($session, $accessToken, $item, $mediaFile, $maxFileBytes);
                    if ($imported === null) {
                        $skipped++;
                    } else {
                        $items[] = $imported;
                    }
                    if ($itemId !== '') {
                        $doneIds[$itemId] = true;
                    }
                    $saveProgress();
                }
            } while ($pageToken !== null);
        } catch (Throwable $e) {
            $saveProgress();
            $session->update(['status' => PickerSessionStatusEnum::Error, 'last_error' => $e->getMessage()]);

            throw $e;
        }

        // Google recommends deleting the session once its items were retrieved
        try {
            Http::withToken($accessToken)->timeout(10)->delete(self::PICKER_BASE.'/sessions/'.urlencode($session->google_session_id));
        } catch (Throwable) {
            // Non-blocking cleanup.
        }

        $saveProgress();
        $session->update(['status' => PickerSessionStatusEnum::Completed, 'import_completed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $mediaFile
     * @return array{id: int, mediaId: string, title: string, mediaUrl: string}|null
     */
    private function importPhoto(GooglePhotosPickerSession $session, string $accessToken, array $item, array $mediaFile, int $maxFileBytes): ?array
    {
        $baseUrl = (string) ($mediaFile['baseUrl'] ?? '');
        if ($baseUrl === '') {
            return null;
        }

        try {
            $download = Http::withToken($accessToken)->timeout(60)->get($baseUrl.'=d');
            $rawBytes = $download->successful() ? $download->body() : '';
            if ($rawBytes === '' || strlen($rawBytes) > $maxFileBytes) {
                return null;
            }

            $userId = $session->user_id;
            $mediaId = 'mem-'.Str::uuid();
            $processed = $this->images->processMultiTierWebp($rawBytes);
            unset($rawBytes);

            foreach (['master', 'display', 'thumb'] as $tier) {
                $enc = $this->crypto->encryptSingleBlock($processed[$tier]['buffer'], [
                    'userId' => $userId,
                    'mediaId' => $mediaId,
                    'variant' => $tier,
                ]);
                $this->storage->putMediaBytes($userId, $mediaId, "{$tier}.webp.enc", $enc);
            }

            $memory = Memory::query()->create([
                'capsule_id' => $session->capsule_id,
                'author_id' => $userId,
                'type' => MemoryTypeEnum::Image,
                'title' => (string) ($mediaFile['filename'] ?? 'Foto do Google Fotos'),
                'content' => '',
                'media_url' => "/api/vault/media/{$mediaId}",
                'storage_key' => "vault/{$userId}/{$mediaId}",
                'mime_type' => 'image/webp',
                'file_size_bytes' => $processed['master']['sizeBytes'],
                'is_encrypted' => true,
                'encryption_mode' => EncryptionModeEnum::SingleBlockGcm,
                'variants_json' => [
                    'master' => ['width' => $processed['master']['width'], 'height' => $processed['master']['height'], 'size' => $processed['master']['sizeBytes'], 'dpi' => 300],
                    'display' => ['width' => $processed['display']['width'], 'height' => $processed['display']['height'], 'size' => $processed['display']['sizeBytes']],
                    'thumb' => ['width' => $processed['thumb']['width'], 'height' => $processed['thumb']['height'], 'size' => $processed['thumb']['sizeBytes']],
                ],
                'dpi' => 300,
                'source' => MemorySourceEnum::GooglePhotos,
                'provider_metadata' => $item,
                'occurred_at' => now(),
            ]);

            return ['id' => $memory->id, 'mediaId' => $mediaId, 'title' => $memory->title, 'mediaUrl' => (string) $memory->media_url];
        } catch (Throwable $e) {
            Log::warning('Google Photos item could not be imported.', ['session' => $session->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Session state for the UI, including import progress.
     *
     * @return array<string, mixed>
     */
    private function presentSession(GooglePhotosPickerSession $session, bool $mediaItemsSet): array
    {
        $progress = is_array($session->metadata['import'] ?? null) ? $session->metadata['import'] : [];
        $items = is_array($progress['items'] ?? null) ? $progress['items'] : [];
        $completed = $session->status === PickerSessionStatusEnum::Completed;

        return [
            'id' => $session->id,
            'sessionId' => $session->google_session_id,
            'pickerUri' => $session->picker_uri,
            'status' => $session->status->value,
            'mediaItemsSet' => $mediaItemsSet,
            'itemCount' => $session->selected_count,
            'selectedCount' => $session->selected_count,
            'importedCount' => count($items),
            'skippedCount' => (int) ($progress['skipped'] ?? 0),
            'skippedVideos' => (int) ($progress['skippedVideos'] ?? 0),
            'alreadyInApp' => (int) ($progress['alreadyInApp'] ?? 0),
            'items' => $completed ? $items : [],
            'error' => $session->status === PickerSessionStatusEnum::Error ? $session->last_error : null,
            'pollingConfig' => ['pollInterval' => '3s'],
        ];
    }

    private function maxItemsPerSession(): int
    {
        return max(1, min(2000, (int) config('ethernity.google.max_items_per_session', 50)));
    }

    public function disconnect(string $userId): void
    {
        $connections = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('provider', 'google_photos')
            ->whereNotNull('credentials_encrypted')
            ->get();

        // Revoke the grant at Google too (not only forget it locally), as the privacy
        // policy states. Best effort: a Google outage must not block disconnecting.
        $encKey = (string) config('ethernity.google.token_encryption_key', '');
        foreach ($connections as $connection) {
            try {
                $credentials = $this->crypto->decryptJson((string) $connection->credentials_encrypted, $encKey);
                $token = (string) ($credentials['refreshToken'] ?? $credentials['accessToken'] ?? '');
                if ($token !== '') {
                    Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
                }
            } catch (Throwable $e) {
                Log::warning('Could not revoke Google Photos grant at Google.', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('provider', 'google_photos')
            ->update([
                'status' => IntegrationStatusEnum::Revoked,
                'credentials_encrypted' => null,
            ]);
    }

    private function findSession(string $userId, string $sessionId): ?GooglePhotosPickerSession
    {
        $trimmed = trim($sessionId);
        if (ctype_digit($trimmed)) {
            return GooglePhotosPickerSession::query()
                ->where('id', (int) $trimmed)
                ->where('user_id', $userId)
                ->first();
        }

        return GooglePhotosPickerSession::query()
            ->where('google_session_id', $trimmed)
            ->where('user_id', $userId)
            ->first();
    }

    private function getValidAccessToken(string $userId): string
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('provider', 'google_photos')
            ->where('status', IntegrationStatusEnum::Active->value)
            ->first();

        if ($connection === null || $connection->credentials_encrypted === null) {
            throw new RuntimeException('Conexão com o Google Fotos não encontrada. Conecte sua conta Google primeiro.');
        }

        $encKey = (string) config('ethernity.google.token_encryption_key', '');
        $credentials = $this->crypto->decryptJson($connection->credentials_encrypted, $encKey);

        $accessToken = (string) ($credentials['accessToken'] ?? '');
        $expiresAtMs = (int) ($credentials['expiresAt'] ?? 0);

        if ($accessToken !== '' && $expiresAtMs > (time() + 120) * 1000) {
            return $accessToken;
        }

        $refreshToken = (string) ($credentials['refreshToken'] ?? '');
        if ($refreshToken === '') {
            if ($accessToken !== '') {
                return $accessToken;
            }
            throw new RuntimeException('Sua autorização com o Google Fotos expirou. Por favor, reconecte sua conta.');
        }

        $refreshResponse = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => (string) config('ethernity.google.client_id'),
            'client_secret' => (string) config('ethernity.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        /** @var array<string, mixed> $refreshData */
        $refreshData = $refreshResponse->json() ?? [];
        if (! $refreshResponse->successful()) {
            if (($refreshData['error'] ?? null) === 'invalid_grant') {
                // The user removed the access at Google (or it expired): show as disconnected
                $connection->update([
                    'status' => IntegrationStatusEnum::Revoked,
                    'credentials_encrypted' => null,
                    'last_error' => 'invalid_grant',
                ]);

                throw new RuntimeException('O acesso ao Google Fotos foi removido ou expirou. Conecte sua conta novamente.');
            }

            throw new RuntimeException('Não foi possível renovar a autorização com o Google Fotos. Tente novamente em instantes.');
        }

        $credentials['accessToken'] = (string) ($refreshData['access_token'] ?? '');
        $credentials['expiresAt'] = (time() + (int) ($refreshData['expires_in'] ?? 3600)) * 1000;
        if (isset($refreshData['refresh_token'])) {
            $credentials['refreshToken'] = (string) $refreshData['refresh_token'];
        }

        $connection->update([
            'credentials_encrypted' => $this->crypto->encryptJson($credentials, $encKey),
            'last_synced_at' => now(),
        ]);

        return $credentials['accessToken'];
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
