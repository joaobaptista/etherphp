<?php

namespace App\Actions\Profile;

use App\Contracts\ImageProcessorContract;
use App\Contracts\VaultStorageServiceContract;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UploadProfileAvatarAction
{
    public function __construct(
        protected ImageProcessorContract $images,
        protected VaultStorageServiceContract $storage,
    ) {}

    /**
     * @return array{url: string, filename: string, user: User}
     */
    public function execute(User $user, UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());
        if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw new HttpException(400, 'Apenas arquivos de imagem são permitidos para a foto de perfil.');
        }

        $raw = (string) file_get_contents($file->getRealPath());

        try {
            $webp = $this->images->normalizeSquarePhoto($raw, 800, 88);
        } catch (Throwable) {
            throw new HttpException(400, 'Arquivo de imagem inválido.');
        }

        $filename = 'avatar-'.$user->id.'-'.time().'.webp';
        $url = $this->storage->putUploadFile($filename, $webp);

        $previousAvatar = $user->avatar_url;
        $user->update(['avatar_url' => $url]);

        if ($previousAvatar !== null && $previousAvatar !== $url) {
            $this->storage->deleteUploadByUrl($previousAvatar);
        }

        return [
            'url' => $url,
            'filename' => $filename,
            'user' => $user->refresh(),
        ];
    }
}
