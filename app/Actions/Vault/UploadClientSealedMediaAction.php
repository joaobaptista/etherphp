<?php

namespace App\Actions\Vault;

use App\Contracts\VaultStorageServiceContract;
use App\Enums\EncryptionModeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Capsule;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadClientSealedMediaAction
{
    private const CLIENT_CHUNK_SIZE = 65536;

    /** @var list<string> */
    private const VARIANTS = ['master', 'display', 'thumb', 'poster'];

    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @param  array{master: UploadedFile, display?: ?UploadedFile, thumb?: ?UploadedFile, poster?: ?UploadedFile}  $files
     * @return array<string, mixed>
     */
    public function execute(User $user, array $fields, array $files): array
    {
        $kind = strtoupper((string) ($fields['kind'] ?? ''));
        $mimeType = strtolower(substr((string) ($fields['mimeType'] ?? ''), 0, 127));

        if (! in_array($kind, ['IMAGE', 'VIDEO', 'AUDIO'], true) || ! str_starts_with($mimeType, strtolower($kind).'/')) {
            throw new HttpException(422, 'Tipo de mídia inválido.');
        }

        $wrappedKey = (string) ($fields['wrappedKey'] ?? '');
        if ($this->decodedLength($wrappedKey) !== 60) {
            throw new HttpException(422, 'Chave do arquivo inválida.');
        }

        if ((int) ($fields['chunkSize'] ?? 0) !== self::CLIENT_CHUNK_SIZE) {
            throw new HttpException(422, 'Tamanho de bloco inválido.');
        }

        $rawIvs = $fields['ivs'] ?? '{}';
        $ivs = is_array($rawIvs) ? $rawIvs : json_decode((string) $rawIvs, true);
        if (! is_array($ivs)) {
            throw new HttpException(422, 'Vetores de inicialização inválidos.');
        }

        $maxBytes = (int) config('ethernity.vault.max_media_bytes', 150 * 1024 * 1024);

        /** @var array<string, UploadedFile> $presentFiles */
        $presentFiles = [];
        foreach (self::VARIANTS as $variant) {
            $candidate = $files[$variant] ?? null;
            if ($candidate !== null) {
                if (($candidate->getSize() ?: 0) > $maxBytes) {
                    throw new HttpException(413, 'Arquivo excede o tamanho máximo permitido no cofre.');
                }
                $presentFiles[$variant] = $candidate;
                if (! isset($ivs[$variant]) || $this->decodedLength((string) $ivs[$variant]) !== 12) {
                    throw new HttpException(422, 'Vetores de inicialização inválidos.');
                }
            }
        }

        $capsuleId = (string) ($fields['capsuleId'] ?? 'capsule-default');
        if ($capsuleId !== 'capsule-default') {
            $capsule = Capsule::query()->find($capsuleId);
            if ($capsule !== null && $capsule->user_id !== $user->id) {
                throw new HttpException(403, 'Esta cápsula pertence a outro usuário.');
            }
        }

        $waveform = isset($fields['waveform']) && is_string($fields['waveform']) && strlen($fields['waveform']) <= 20000
            ? $fields['waveform']
            : null;
        $durationSeconds = max(0, (int) round((float) ($fields['durationSeconds'] ?? 0))) ?: null;

        $mediaId = 'mem-'.Str::uuid();
        $variantSizes = [];
        $usedIvs = [];

        foreach ($presentFiles as $variant => $variantFile) {
            $realPath = $variantFile->getRealPath();
            if ($realPath === false) {
                throw new HttpException(422, 'Falha ao ler o arquivo enviado.');
            }
            $storedPath = $this->storage->putMediaFromFile($user->id, $mediaId, "{$variant}.cenc", $realPath);
            $variantSizes[$variant] = ['size' => (int) (filesize($storedPath) ?: 0)];
            $usedIvs[$variant] = (string) $ivs[$variant];
        }

        $variantsJson = [
            'client' => [
                'version' => 1,
                'wrappedKey' => $wrappedKey,
                'ivs' => $usedIvs,
                'chunkSize' => self::CLIENT_CHUNK_SIZE,
                'variants' => $variantSizes,
            ],
        ];

        $mediaUrl = $kind === 'IMAGE'
            ? "/api/vault/media/{$mediaId}"
            : "/api/vault/media/{$mediaId}/stream";

        $memory = Memory::query()->create([
            'capsule_id' => $capsuleId,
            'author_id' => $user->id,
            'type' => MemoryTypeEnum::from($kind),
            'title' => isset($fields['title']) && (string) $fields['title'] !== '' ? (string) $fields['title'] : null,
            'content' => (string) ($fields['description'] ?? ''),
            'media_url' => $mediaUrl,
            'storage_key' => "vault/{$user->id}/{$mediaId}",
            'mime_type' => $mimeType,
            'file_size_bytes' => $files['master']->getSize() ?: 0,
            'is_encrypted' => true,
            'encryption_mode' => EncryptionModeEnum::ClientChunkedV1,
            'variants_json' => $variantsJson,
            'media_duration_seconds' => $durationSeconds,
            'waveform_peaks' => $waveform,
            'chunk_size_bytes' => self::CLIENT_CHUNK_SIZE,
            'source' => MemorySourceEnum::Upload,
            'occurred_at' => now(),
        ]);

        return [
            'id' => $memory->id,
            'mediaId' => $mediaId,
            'type' => $kind,
            'mimeType' => $mimeType,
            'clientEncrypted' => true,
            'mediaUrl' => $mediaUrl,
            'streamUrl' => $kind === 'IMAGE' ? null : $mediaUrl,
            'posterUrl' => isset($files['poster']) ? "/api/vault/media/{$mediaId}/poster" : null,
            'durationSeconds' => $durationSeconds,
        ];
    }

    private function decodedLength(string $value): int
    {
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) !== 1) {
            return -1;
        }
        $decoded = base64_decode($value, true);

        return $decoded === false ? -1 : strlen($decoded);
    }
}
