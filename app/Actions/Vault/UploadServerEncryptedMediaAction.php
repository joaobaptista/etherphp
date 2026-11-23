<?php

namespace App\Actions\Vault;

use App\Contracts\ImageProcessorContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use App\Contracts\VideoAudioProcessorContract;
use App\Enums\EncryptionModeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Capsule;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UploadServerEncryptedMediaAction
{
    public function __construct(
        protected VaultCryptoServiceContract $crypto,
        protected VaultStorageServiceContract $storage,
        protected ImageProcessorContract $images,
        protected VideoAudioProcessorContract $media,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function execute(User $user, UploadedFile $file, array $fields, ?UploadedFile $liveVideoFile = null): array
    {
        $maxBytes = (int) config('ethernity.vault.max_media_bytes', 150 * 1024 * 1024);
        if (($file->getSize() ?: 0) > $maxBytes) {
            throw new HttpException(413, 'Arquivo excede o tamanho máximo permitido no cofre.');
        }

        $mime = strtolower((string) $file->getMimeType());
        $ext = strtolower($file->getClientOriginalExtension());
        $isHeic = in_array($ext, ['heic', 'heif'], true) || in_array($mime, ['image/heic', 'image/heif'], true);
        $isImage = (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') || $isHeic;
        $isVideo = str_starts_with($mime, 'video/');
        $isAudio = str_starts_with($mime, 'audio/');

        if (! $isImage && ! $isVideo && ! $isAudio) {
            throw new HttpException(400, 'Tipo de mídia não suportado. Envie imagem, vídeo ou áudio.');
        }

        $capsuleId = (string) ($fields['capsuleId'] ?? 'capsule-default');
        if ($capsuleId !== 'capsule-default') {
            $capsule = Capsule::query()->find($capsuleId);
            if ($capsule !== null && $capsule->user_id !== $user->id) {
                throw new HttpException(403, 'Esta cápsula pertence a outro usuário.');
            }
        }

        $mediaId = 'mem-'.Str::uuid();
        $title = (string) ($fields['title'] ?? $file->getClientOriginalName() ?: 'Arquivo no Cofre');
        $description = (string) ($fields['description'] ?? '');

        if ($isImage) {
            return $this->handleImage($user, $capsuleId, $mediaId, $title, $description, $file, $isHeic, $ext, $liveVideoFile);
        }

        if ($isVideo) {
            return $this->handleVideo($user, $capsuleId, $mediaId, $title, $description, $file);
        }

        return $this->handleAudio($user, $capsuleId, $mediaId, $title, $description, $file);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleImage(
        User $user,
        string $capsuleId,
        string $mediaId,
        string $title,
        string $description,
        UploadedFile $file,
        bool $isHeic,
        string $ext,
        ?UploadedFile $liveVideoFile,
    ): array {
        $rawBytes = (string) file_get_contents($file->getRealPath());

        try {
            $processed = $this->images->processMultiTierWebp($rawBytes);
        } catch (Throwable) {
            $msg = $isHeic
                ? 'Não foi possível decodificar esta imagem HEIC/HEIF neste servidor. Instale a extensão Imagick ou envie em JPEG/PNG/WebP.'
                : 'Arquivo de imagem inválido ou corrompido.';
            throw new HttpException(422, $msg);
        }

        foreach (['master', 'display', 'thumb'] as $tier) {
            $enc = $this->crypto->encryptSingleBlock($processed[$tier]['buffer'], [
                'userId' => $user->id,
                'mediaId' => $mediaId,
                'variant' => $tier,
            ]);
            $this->storage->putMediaBytes($user->id, $mediaId, "{$tier}.webp.enc", $enc);
        }

        $variantsJson = [
            'master' => ['width' => $processed['master']['width'], 'height' => $processed['master']['height'], 'size' => $processed['master']['sizeBytes'], 'dpi' => 300],
            'display' => ['width' => $processed['display']['width'], 'height' => $processed['display']['height'], 'size' => $processed['display']['sizeBytes']],
            'thumb' => ['width' => $processed['thumb']['width'], 'height' => $processed['thumb']['height'], 'size' => $processed['thumb']['sizeBytes']],
            'isHeic' => $isHeic,
            'originalFormat' => $isHeic ? 'heic' : ($ext !== '' ? $ext : 'jpeg'),
        ];

        $isLivePhoto = false;
        if ($liveVideoFile !== null) {
            $tmpOut = sys_get_temp_dir().'/ethernity-live-'.Str::uuid().'.mp4';
            try {
                $this->media->processVideo($liveVideoFile->getRealPath(), $tmpOut);
                $encPath = $this->storage->mediaFilePath($user->id, $mediaId, 'live_video.mp4.enc');
                $this->crypto->encryptChunkedFile($tmpOut, $encPath, ['userId' => $user->id, 'mediaId' => $mediaId]);
            } finally {
                @unlink($tmpOut);
            }
            $isLivePhoto = true;
            $variantsJson['isLivePhoto'] = true;
            $variantsJson['liveVideoUrl'] = "/api/vault/media/{$mediaId}/live-video";
        }

        $mediaUrl = "/api/vault/media/{$mediaId}";
        $memory = Memory::query()->create([
            'capsule_id' => $capsuleId,
            'author_id' => $user->id,
            'type' => MemoryTypeEnum::Image,
            'title' => $title,
            'content' => $description,
            'media_url' => $mediaUrl,
            'storage_key' => "vault/{$user->id}/{$mediaId}",
            'mime_type' => 'image/webp',
            'file_size_bytes' => $processed['master']['sizeBytes'],
            'is_encrypted' => true,
            'encryption_mode' => EncryptionModeEnum::SingleBlockGcm,
            'variants_json' => $variantsJson,
            'dpi' => 300,
            'source' => MemorySourceEnum::Upload,
            'occurred_at' => now(),
        ]);

        return [
            'id' => $memory->id,
            'mediaId' => $mediaId,
            'type' => MemoryTypeEnum::Image->value,
            'mimeType' => 'image/webp',
            'mediaUrl' => $mediaUrl,
            'thumbUrl' => "{$mediaUrl}?variant=thumb",
            'displayUrl' => "{$mediaUrl}?variant=display",
            'masterUrl' => "{$mediaUrl}?variant=master",
            'isLivePhoto' => $isLivePhoto,
            'liveVideoUrl' => $isLivePhoto ? "{$mediaUrl}/live-video" : null,
            'variants' => $variantsJson,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleVideo(
        User $user,
        string $capsuleId,
        string $mediaId,
        string $title,
        string $description,
        UploadedFile $file,
    ): array {
        $tmpOut = sys_get_temp_dir().'/ethernity-vid-'.Str::uuid().'.mp4';
        try {
            $processed = $this->media->processVideo($file->getRealPath(), $tmpOut);
            $encPath = $this->storage->mediaFilePath($user->id, $mediaId, 'video.mp4.enc');
            $stats = $this->crypto->encryptChunkedFile($tmpOut, $encPath, ['userId' => $user->id, 'mediaId' => $mediaId]);
        } finally {
            @unlink($tmpOut);
        }

        $posterUrl = null;
        if ($processed['posterBytes'] !== null) {
            $encPoster = $this->crypto->encryptSingleBlock($processed['posterBytes'], [
                'userId' => $user->id,
                'mediaId' => $mediaId,
                'variant' => 'poster',
            ]);
            $this->storage->putMediaBytes($user->id, $mediaId, 'poster.webp.enc', $encPoster);
            $posterUrl = "/api/vault/media/{$mediaId}/poster";
        }

        $streamUrl = "/api/vault/media/{$mediaId}/stream";
        $memory = Memory::query()->create([
            'capsule_id' => $capsuleId,
            'author_id' => $user->id,
            'type' => MemoryTypeEnum::Video,
            'title' => $title,
            'content' => $description,
            'media_url' => $streamUrl,
            'storage_key' => "vault/{$user->id}/{$mediaId}",
            'mime_type' => 'video/mp4',
            'file_size_bytes' => $stats['totalBytes'],
            'is_encrypted' => true,
            'encryption_mode' => EncryptionModeEnum::ChunkedAead,
            'chunk_size_bytes' => $stats['chunkSize'],
            'media_duration_seconds' => $processed['durationSeconds'],
            'source' => MemorySourceEnum::Upload,
            'occurred_at' => now(),
        ]);

        return [
            'id' => $memory->id,
            'mediaId' => $mediaId,
            'type' => MemoryTypeEnum::Video->value,
            'mimeType' => 'video/mp4',
            'mediaUrl' => $streamUrl,
            'streamUrl' => $streamUrl,
            'posterUrl' => $posterUrl,
            'durationSeconds' => $processed['durationSeconds'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAudio(
        User $user,
        string $capsuleId,
        string $mediaId,
        string $title,
        string $description,
        UploadedFile $file,
    ): array {
        $tmpOut = sys_get_temp_dir().'/ethernity-aud-'.Str::uuid().'.m4a';
        try {
            $processed = $this->media->processAudio($file->getRealPath(), $tmpOut);
            $encPath = $this->storage->mediaFilePath($user->id, $mediaId, 'audio.m4a.enc');
            $stats = $this->crypto->encryptChunkedFile($tmpOut, $encPath, ['userId' => $user->id, 'mediaId' => $mediaId]);
        } finally {
            @unlink($tmpOut);
        }

        $streamUrl = "/api/vault/media/{$mediaId}/stream";
        $memory = Memory::query()->create([
            'capsule_id' => $capsuleId,
            'author_id' => $user->id,
            'type' => MemoryTypeEnum::Audio,
            'title' => $title,
            'content' => $description,
            'media_url' => $streamUrl,
            'storage_key' => "vault/{$user->id}/{$mediaId}",
            'mime_type' => 'audio/mp4',
            'file_size_bytes' => $stats['totalBytes'],
            'is_encrypted' => true,
            'encryption_mode' => EncryptionModeEnum::ChunkedAead,
            'chunk_size_bytes' => $stats['chunkSize'],
            'media_duration_seconds' => $processed['durationSeconds'],
            'waveform_peaks' => $processed['waveformPeaks'],
            'source' => MemorySourceEnum::Upload,
            'occurred_at' => now(),
        ]);

        return [
            'id' => $memory->id,
            'mediaId' => $mediaId,
            'type' => MemoryTypeEnum::Audio->value,
            'mimeType' => 'audio/mp4',
            'mediaUrl' => $streamUrl,
            'streamUrl' => $streamUrl,
            'waveformUrl' => "/api/vault/media/{$mediaId}/waveform",
            'waveformPeaks' => $processed['waveformPeaks'],
            'durationSeconds' => $processed['durationSeconds'],
        ];
    }
}
