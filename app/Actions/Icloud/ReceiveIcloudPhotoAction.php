<?php

namespace App\Actions\Icloud;

use App\Contracts\ImageProcessorContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use App\Enums\EncryptionModeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Stores a JPEG sent by the native iOS app (iCloud / camera roll) in the user's vault.
 *
 * The photo lands without a capsule ("waiting for a destination"), like Google Photos
 * imports. The SHA-256 of the body is the idempotency key: resending the same file
 * returns the existing memory instead of creating a duplicate.
 */
class ReceiveIcloudPhotoAction
{
    public function __construct(
        protected ImageProcessorContract $images,
        protected VaultCryptoServiceContract $crypto,
        protected VaultStorageServiceContract $storage,
    ) {}

    /**
     * @param  array{source?: ?string, assetLocalId?: ?string, assetCloudId?: ?string, icloudOnly?: ?string}  $meta
     * @return array{created: bool, memory: Memory}
     */
    public function execute(User $user, string $sha256, string $bytes, array $meta = []): array
    {
        $sha256 = strtolower($sha256);

        if ($bytes === '') {
            throw new HttpException(422, 'Nenhuma foto recebida.');
        }
        if (strlen($bytes) > (int) config('ethernity.vault.max_media_bytes', 150 * 1024 * 1024)) {
            throw new HttpException(413, 'Arquivo excede o tamanho máximo permitido no cofre.');
        }
        if (! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new HttpException(422, 'O SHA-256 da foto não confere com o enviado.');
        }
        if (! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            throw new HttpException(415, 'Envie a foto em JPEG.');
        }

        // Two retries of the same photo can arrive together: only one creates the memory
        return Cache::lock("icloud-photo:{$user->id}:{$sha256}", 120)->block(60, function () use ($user, $sha256, $bytes, $meta): array {
            $existing = $this->findExisting($user, $sha256, $meta['assetCloudId'] ?? null);
            if ($existing !== null) {
                return ['created' => false, 'memory' => $existing];
            }

            return ['created' => true, 'memory' => $this->store($user, $sha256, $bytes, $meta)];
        });
    }

    private function findExisting(User $user, string $sha256, ?string $assetCloudId): ?Memory
    {
        return Memory::query()
            ->where('author_id', $user->id)
            ->where('source', MemorySourceEnum::Icloud)
            ->where(function ($query) use ($sha256, $assetCloudId): void {
                $query->where('provider_metadata->sha256', $sha256);
                if ($assetCloudId !== null && $assetCloudId !== '') {
                    $query->orWhere('provider_metadata->assetCloudId', $assetCloudId);
                }
            })
            ->first();
    }

    /**
     * @param  array{source?: ?string, assetLocalId?: ?string, assetCloudId?: ?string, icloudOnly?: ?string}  $meta
     */
    private function store(User $user, string $sha256, string $bytes, array $meta): Memory
    {
        $takenAt = $this->captureDate($bytes);
        $mediaId = 'mem-'.Str::uuid();
        $processed = $this->images->processMultiTierWebp($bytes);

        foreach (['master', 'display', 'thumb'] as $tier) {
            $enc = $this->crypto->encryptSingleBlock($processed[$tier]['buffer'], [
                'userId' => $user->id,
                'mediaId' => $mediaId,
                'variant' => $tier,
            ]);
            $this->storage->putMediaBytes($user->id, $mediaId, "{$tier}.webp.enc", $enc);
        }

        return Memory::query()->create([
            'capsule_id' => 'capsule-default',
            'author_id' => $user->id,
            'type' => MemoryTypeEnum::Image,
            'title' => 'Foto do iPhone',
            'content' => '',
            'media_url' => "/api/vault/media/{$mediaId}",
            'storage_key' => "vault/{$user->id}/{$mediaId}",
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
            'source' => MemorySourceEnum::Icloud,
            'provider_metadata' => array_filter([
                'sha256' => $sha256,
                'source' => $meta['source'] ?? null,
                'assetLocalId' => $meta['assetLocalId'] ?? null,
                'assetCloudId' => $meta['assetCloudId'] ?? null,
                'icloudOnly' => $meta['icloudOnly'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'occurred_at' => $takenAt ?? now(),
        ]);
    }

    /**
     * Capture date from EXIF (the iOS app keeps it; GPS is removed before sending).
     */
    private function captureDate(string $bytes): ?Carbon
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes), 'EXIF');
            $raw = is_array($exif) ? ($exif['DateTimeOriginal'] ?? null) : null;

            return is_string($raw) ? Carbon::createFromFormat('Y:m:d H:i:s', $raw) ?: null : null;
        } catch (Throwable) {
            return null;
        }
    }
}
