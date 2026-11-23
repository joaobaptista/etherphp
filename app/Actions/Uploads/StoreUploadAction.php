<?php

namespace App\Actions\Uploads;

use App\Contracts\VaultStorageServiceContract;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreUploadAction
{
    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/avif' => 'avif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'video/x-matroska' => 'mkv',
        'video/ogg' => 'ogv',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/webm' => 'weba',
        'audio/ogg' => 'ogg',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
        'text/plain' => 'txt',
        'text/markdown' => 'md',
        'text/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/json' => 'json',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    /**
     * @return array{filename: string, url: string, photo_url: string, fileUrl: string}
     */
    public function execute(User $user, UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());
        /** @var list<string> $allowed */
        $allowed = config('ethernity.uploads.allowed_mimes', []);
        $maxBytes = (int) config('ethernity.uploads.max_bytes', 50 * 1024 * 1024);

        if ($mime === 'image/svg+xml' || ($allowed !== [] && ! in_array($mime, $allowed, true))) {
            throw new HttpException(400, 'File type not allowed');
        }

        if ($file->getSize() > $maxBytes) {
            throw new HttpException(400, 'File too large');
        }

        $safeExt = self::MIME_EXTENSIONS[$mime] ?? 'bin';
        $filename = Str::uuid().'.'.$safeExt;
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new HttpException(400, 'Invalid uploaded file');
        }

        $url = $this->storage->putUploadFromFile("{$user->id}/{$filename}", $realPath);

        return [
            'filename' => $filename,
            'url' => $url,
            'photo_url' => $url,
            'fileUrl' => $url,
        ];
    }
}
