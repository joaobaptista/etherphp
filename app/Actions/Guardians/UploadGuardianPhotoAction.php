<?php

namespace App\Actions\Guardians;

use App\Contracts\ImageProcessorContract;
use App\Contracts\VaultStorageServiceContract;
use App\Models\Guardian;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UploadGuardianPhotoAction
{
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        protected ImageProcessorContract $images,
        protected VaultStorageServiceContract $storage,
    ) {}

    public function execute(Guardian $guardian, UploadedFile $file): Guardian
    {
        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, self::ALLOWED_TYPES, true)) {
            throw new HttpException(400, 'Formato de foto não suportado. Use JPEG, PNG, WebP ou HEIC.');
        }

        $raw = (string) file_get_contents($file->getRealPath());

        try {
            $webp = $this->images->normalizeSquarePhoto($raw, 800, 88);
        } catch (Throwable) {
            throw new HttpException(400, 'Arquivo de imagem inválido.');
        }

        $filename = 'guardian-'.Str::uuid().'.webp';
        $avatarUrl = $this->storage->putUploadFile("{$guardian->user_id}/{$filename}", $webp);

        $previous = $guardian->avatar_url;
        $guardian->update(['avatar_url' => $avatarUrl]);

        if ($previous !== null && $previous !== $avatarUrl) {
            $this->storage->deleteUploadByUrl($previous);
        }

        return $guardian->refresh();
    }
}
