<?php

namespace App\Http\Controllers\Api;

use App\Actions\Vault\DeleteVaultMediaAction;
use App\Actions\Vault\FindAuthorizedMemoryAction;
use App\Actions\Vault\UploadClientSealedMediaAction;
use App\Actions\Vault\UploadServerEncryptedMediaAction;
use App\Contracts\ImageProcessorContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use App\Enums\EncryptionModeEnum;
use App\Enums\MemoryTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Memory;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Cofre', weight: 80)]
class VaultMediaController extends Controller
{
    /**
     * Enviar mídia para o cofre
     *
     * Envio `multipart/form-data`. Imagens viram WebP em três tamanhos (thumb, display, master), vídeos viram MP4 e áudios viram AAC; tudo é gravado criptografado. Com `clientEncryption=v1`, o arquivo já chega cifrado pelo navegador.
     *
     * @requestMediaType multipart/form-data
     */
    #[BodyParameter('file', 'Imagem, vídeo ou áudio (também aceito como `media`, `photo`, `video` ou `audio`).', required: true, type: 'string', format: 'binary')]
    #[BodyParameter('livePhotoVideo', 'Vídeo da Live Photo da Apple (opcional).', type: 'string', format: 'binary')]
    #[BodyParameter('clientEncryption', 'Use `v1` quando o arquivo já vier cifrado pelo cliente.', type: 'string')]
    #[BodyParameter('capsuleId', 'Cápsula de destino.', type: 'string')]
    public function upload(
        Request $request,
        UploadClientSealedMediaAction $clientSealed,
        UploadServerEncryptedMediaAction $serverEncrypted,
    ): JsonResponse {
        $file = $this->firstUploadedFile($request, ['file', 'media', 'photo', 'video', 'audio']);
        if ($file === null) {
            return response()->json(['detail' => 'Nenhum arquivo enviado.'], 422);
        }

        /** @var User $user */
        $user = $request->user();

        if ($request->input('clientEncryption') === 'v1') {
            return response()->json($clientSealed->execute($user, $request->all(), [
                'master' => $file,
                'display' => $this->firstUploadedFile($request, ['display']),
                'thumb' => $this->firstUploadedFile($request, ['thumb']),
                'poster' => $this->firstUploadedFile($request, ['poster']),
            ]));
        }

        $liveVideo = $this->firstUploadedFile($request, ['livePhotoVideo', 'live_video']);

        return response()->json($serverEncrypted->execute($user, $file, $request->all(), $liveVideo));
    }

    /**
     * Metadados da mídia
     */
    public function meta(Request $request, string $id, FindAuthorizedMemoryAction $find): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        if ($memory->encryption_mode !== EncryptionModeEnum::ClientChunkedV1) {
            return response()->json([
                'mode' => 'server',
                'type' => $memory->type->value,
                'mimeType' => $memory->mime_type,
            ])->header('Cache-Control', 'no-store');
        }

        $meta = $this->clientMeta($memory);
        $variants = is_array($meta['variants'] ?? null) ? array_keys($meta['variants']) : [];

        return response()->json([
            'mode' => 'client',
            'type' => $memory->type->value,
            'mimeType' => $memory->mime_type,
            'wrappedKey' => $meta['wrappedKey'] ?? null,
            'ivs' => $meta['ivs'] ?? [],
            'chunkSize' => $meta['chunkSize'] ?? 65536,
            'variants' => $variants,
            'durationSeconds' => $memory->media_duration_seconds,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Baixar uma imagem
     *
     * Aceita `?token=` para uso em `<img>`.
     */
    #[QueryParameter('variant', 'Tamanho da imagem.', type: "'thumb'|'display'|'master'", default: 'display')]
    #[QueryParameter('token', 'JWT, para quando não dá para enviar o cabeçalho Authorization.', type: 'string')]
    public function show(
        Request $request,
        string $id,
        FindAuthorizedMemoryAction $find,
        VaultStorageServiceContract $storage,
        VaultCryptoServiceContract $crypto,
    ): Response|BinaryFileResponse {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        $rawVariant = (string) $request->query('variant', 'display');
        $variant = in_array($rawVariant, ['thumb', 'display', 'master'], true) ? $rawVariant : 'display';

        if ($memory->encryption_mode === EncryptionModeEnum::ClientChunkedV1) {
            return $this->sendClientVariant($memory, $variant, $storage);
        }

        $mediaId = basename((string) $memory->storage_key);
        $encBytes = $storage->getMediaBytes($memory->author_id, $mediaId, "{$variant}.webp.enc");
        $usedVariant = $variant;

        if ($encBytes === null) {
            $encBytes = $storage->getMediaBytes($memory->author_id, $mediaId, 'master.webp.enc');
            $usedVariant = 'master';
        }

        abort_if($encBytes === null, 404, 'Arquivo não encontrado no cofre.');

        $plain = $crypto->decryptSingleBlock($encBytes, [
            'userId' => $memory->author_id,
            'mediaId' => $mediaId,
            'variant' => $usedVariant,
        ]);

        return response($plain, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($plain),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Tocar vídeo ou áudio
     *
     * Suporta `Range` (206). Aceita `?token=`.
     */
    #[QueryParameter('token', 'JWT, para quando não dá para enviar o cabeçalho Authorization.', type: 'string')]
    public function stream(
        Request $request,
        string $id,
        FindAuthorizedMemoryAction $find,
        VaultStorageServiceContract $storage,
        VaultCryptoServiceContract $crypto,
    ): Response|BinaryFileResponse|StreamedResponse {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        if ($memory->encryption_mode === EncryptionModeEnum::ClientChunkedV1) {
            return $this->sendClientVariant($memory, 'master', $storage);
        }

        $mediaId = basename((string) $memory->storage_key);
        $isVideo = $memory->type === MemoryTypeEnum::Video;
        $encFile = $storage->mediaFilePath($memory->author_id, $mediaId, $isVideo ? 'video.mp4.enc' : 'audio.m4a.enc');
        $mimeType = $isVideo ? 'video/mp4' : 'audio/mp4';

        return $this->streamChunkedFile($request, $encFile, $mimeType, $memory->author_id, $mediaId, $crypto);
    }

    /**
     * Capa do vídeo
     *
     * Aceita `?token=`.
     */
    #[QueryParameter('token', 'JWT, para quando não dá para enviar o cabeçalho Authorization.', type: 'string')]
    public function poster(
        Request $request,
        string $id,
        FindAuthorizedMemoryAction $find,
        VaultStorageServiceContract $storage,
        VaultCryptoServiceContract $crypto,
    ): Response|BinaryFileResponse|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        if ($memory->encryption_mode === EncryptionModeEnum::ClientChunkedV1) {
            $meta = $this->clientMeta($memory);
            if (! is_array($meta['variants'] ?? null) || ! isset($meta['variants']['poster'])) {
                return response()->json(['detail' => 'Sem pôster.'], 404);
            }

            return $this->sendClientVariant($memory, 'poster', $storage);
        }

        $mediaId = basename((string) $memory->storage_key);
        $encBytes = $storage->getMediaBytes($memory->author_id, $mediaId, 'poster.webp.enc');
        abort_if($encBytes === null, 404, 'Sem pôster.');

        $plain = $crypto->decryptSingleBlock($encBytes, [
            'userId' => $memory->author_id,
            'mediaId' => $mediaId,
            'variant' => 'poster',
        ]);

        return response($plain, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($plain),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Forma de onda do áudio
     *
     * Aceita `?token=`.
     */
    #[QueryParameter('token', 'JWT, para quando não dá para enviar o cabeçalho Authorization.', type: 'string')]
    public function waveform(Request $request, string $id, FindAuthorizedMemoryAction $find): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        if ($memory->encryption_mode === EncryptionModeEnum::ClientChunkedV1) {
            $sealed = is_string($memory->waveform_peaks) ? $memory->waveform_peaks : null;

            return response()->json([
                'sealedWaveform' => $sealed,
                'durationSeconds' => (int) ($memory->media_duration_seconds ?? 0),
            ]);
        }

        $peaks = is_array($memory->waveform_peaks) ? $memory->waveform_peaks : array_fill(0, 100, 0.2);

        return response()->json([
            'waveformPeaks' => $peaks,
            'durationSeconds' => (int) ($memory->media_duration_seconds ?? 0),
        ]);
    }

    /**
     * Vídeo da Live Photo
     *
     * Suporta `Range` (206). Aceita `?token=`.
     */
    #[QueryParameter('token', 'JWT, para quando não dá para enviar o cabeçalho Authorization.', type: 'string')]
    public function liveVideo(
        Request $request,
        string $id,
        FindAuthorizedMemoryAction $find,
        VaultStorageServiceContract $storage,
        VaultCryptoServiceContract $crypto,
    ): Response|StreamedResponse|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        if ($memory->encryption_mode === EncryptionModeEnum::ClientChunkedV1) {
            return response()->json(['detail' => 'Sem vídeo de Live Photo.'], 404);
        }

        $mediaId = basename((string) $memory->storage_key);
        $encFile = $storage->mediaFilePath($memory->author_id, $mediaId, 'live_video.mp4.enc');
        abort_if(! is_file($encFile), 404, 'Sem vídeo de Live Photo.');

        return $this->streamChunkedFile($request, $encFile, 'video/mp4', $memory->author_id, $mediaId, $crypto);
    }

    /**
     * Exportar o original decifrado
     *
     * Aceita `?token=`.
     */
    #[QueryParameter('token', 'JWT, para quando não dá para enviar o cabeçalho Authorization.', type: 'string')]
    public function export(
        Request $request,
        string $id,
        FindAuthorizedMemoryAction $find,
        VaultStorageServiceContract $storage,
        VaultCryptoServiceContract $crypto,
        ImageProcessorContract $images,
    ): Response|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $memory = $find->execute($user, $id);

        if ($memory->encryption_mode === EncryptionModeEnum::ClientChunkedV1) {
            return response()->json(['detail' => 'Este arquivo é aberto e exportado no próprio aparelho.'], 409);
        }

        $mediaId = basename((string) $memory->storage_key);
        $encBytes = $storage->getMediaBytes($memory->author_id, $mediaId, 'master.webp.enc');
        abort_if($encBytes === null, 404, 'Arquivo mestre não encontrado.');

        $master = $crypto->decryptSingleBlock($encBytes, [
            'userId' => $memory->author_id,
            'mediaId' => $mediaId,
            'variant' => 'master',
        ]);

        $format = (string) $request->query('format', 'png');
        $base = 'ethernity-'.($memory->title !== null && $memory->title !== '' ? str_replace(' ', '-', $memory->title) : 'memoria');
        $exported = $images->exportForPrint($master, $format, $base);

        return response($exported['buffer'], 200, [
            'Content-Type' => $exported['mimeType'],
            'Content-Length' => (string) strlen($exported['buffer']),
            'Content-Disposition' => 'attachment; filename="'.$exported['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Excluir mídia do cofre
     */
    public function destroy(Request $request, string $id, DeleteVaultMediaAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $deleted = $action->execute($user, $id);

        return response()->json([
            'success' => true,
            'message' => 'Memória expurgada com sucesso e destruída criptograficamente.',
            'id' => $deleted->id,
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    private function firstUploadedFile(Request $request, array $names): ?UploadedFile
    {
        foreach ($names as $name) {
            $file = $request->file($name);
            if ($file instanceof UploadedFile) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientMeta(Memory $memory): array
    {
        $variants = is_array($memory->variants_json) ? $memory->variants_json : [];

        return is_array($variants['client'] ?? null) ? $variants['client'] : [];
    }

    private function sendClientVariant(Memory $memory, string $variant, VaultStorageServiceContract $storage): BinaryFileResponse
    {
        $meta = $this->clientMeta($memory);
        $chosen = is_array($meta['variants'] ?? null) && isset($meta['variants'][$variant]) ? $variant : 'master';
        $mediaId = basename((string) $memory->storage_key);
        $path = $storage->mediaFilePath($memory->author_id, $mediaId, "{$chosen}.cenc");

        abort_if(! is_file($path), 404, 'Arquivo cifrado não encontrado.');

        return response()->file($path, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'X-Ethernity-Variant' => $chosen,
        ]);
    }

    private function streamChunkedFile(
        Request $request,
        string $encFile,
        string $mimeType,
        string $authorId,
        string $mediaId,
        VaultCryptoServiceContract $crypto,
    ): Response|StreamedResponse {
        abort_if(! is_file($encFile), 404, 'Arquivo de mídia não encontrado.');

        $header = $crypto->readChunkedHeader($encFile);
        $totalBytes = $header['totalBytes'];
        $chunkSize = max(1, $header['chunkSize']);
        $rangeHeader = $request->headers->get('Range');

        if (is_string($rangeHeader) && str_starts_with($rangeHeader, 'bytes=')) {
            $rangeSpec = explode(',', substr($rangeHeader, 6), 2)[0];
            $parts = explode('-', trim($rangeSpec), 2);

            if ($totalBytes === 0) {
                return response('', 416, [
                    'Content-Range' => 'bytes */0',
                ]);
            }

            $rawStart = trim($parts[0]);
            $rawEnd = isset($parts[1]) ? trim($parts[1]) : '';

            if ($rawStart === '' && $rawEnd !== '' && ctype_digit($rawEnd)) {
                // Suffix byte range: bytes=-500
                $suffixLen = (int) $rawEnd;
                if ($suffixLen <= 0) {
                    return response('', 416, ['Content-Range' => "bytes */{$totalBytes}"]);
                }
                $start = max(0, $totalBytes - $suffixLen);
                $end = $totalBytes - 1;
            } elseif ($rawStart !== '' && ctype_digit($rawStart) && ($rawEnd === '' || ctype_digit($rawEnd))) {
                $start = (int) $rawStart;
                $end = $rawEnd !== '' ? min((int) $rawEnd, $totalBytes - 1) : $totalBytes - 1;
            } else {
                return response('', 416, ['Content-Range' => "bytes */{$totalBytes}"]);
            }

            if ($start >= $totalBytes || $start > $end) {
                return response('', 416, ['Content-Range' => "bytes */{$totalBytes}"]);
            }

            $chunk = $crypto->decryptChunkedRange($encFile, $start, $end, [
                'userId' => $authorId,
                'mediaId' => $mediaId,
            ]);

            return response($chunk['buffer'], 206, [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes {$chunk['start']}-{$chunk['end']}/{$totalBytes}",
                'Content-Length' => (string) strlen($chunk['buffer']),
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Full 200 OK streamed response when no Range header is sent
        return response()->stream(function () use ($encFile, $totalBytes, $chunkSize, $authorId, $mediaId, $crypto): void {
            if ($totalBytes === 0) {
                return;
            }

            $step = $chunkSize * 4;
            for ($offset = 0; $offset < $totalBytes; $offset += $step) {
                $end = min($totalBytes - 1, $offset + $step - 1);
                $slice = $crypto->decryptChunkedRange($encFile, $offset, $end, [
                    'userId' => $authorId,
                    'mediaId' => $mediaId,
                ]);
                echo $slice['buffer'];
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $totalBytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
