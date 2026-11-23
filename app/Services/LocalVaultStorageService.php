<?php

namespace App\Services;

use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LocalVaultStorageService implements VaultStorageServiceContract
{
    public function __construct(
        protected VaultCryptoServiceContract $crypto,
    ) {}

    public function mediaDirectory(string $userId, string $mediaId): string
    {
        $safeUser = $this->sanitizeSegment($userId);
        $safeMedia = $this->sanitizeSegment($mediaId);
        $dir = $this->vaultRoot().DIRECTORY_SEPARATOR.$safeUser.DIRECTORY_SEPARATOR.$safeMedia;

        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    public function mediaFilePath(string $userId, string $mediaId, string $filename): string
    {
        $safeName = basename($filename);

        return $this->mediaDirectory($userId, $mediaId).DIRECTORY_SEPARATOR.$safeName;
    }

    public function putMediaBytes(string $userId, string $mediaId, string $filename, string $bytes): string
    {
        $path = $this->mediaFilePath($userId, $mediaId, $filename);
        file_put_contents($path, $bytes, LOCK_EX);

        return $path;
    }

    public function putMediaFromFile(string $userId, string $mediaId, string $filename, string $sourceFilePath): string
    {
        $path = $this->mediaFilePath($userId, $mediaId, $filename);
        if (! copy($sourceFilePath, $path)) {
            throw new RuntimeException('Failed to store encrypted media file.');
        }

        return $path;
    }

    public function getMediaBytes(string $userId, string $mediaId, string $filename): ?string
    {
        $path = $this->mediaFilePath($userId, $mediaId, $filename);
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function mediaFileExists(string $userId, string $mediaId, string $filename): bool
    {
        return is_file($this->mediaFilePath($userId, $mediaId, $filename));
    }

    public function shredMedia(string $userId, ?string $storageKey): void
    {
        if ($storageKey === null || trim($storageKey) === '') {
            return;
        }

        $mediaId = basename($storageKey);
        if ($mediaId === '' || $mediaId === '.' || $mediaId === '..') {
            return;
        }

        $safeUser = $this->sanitizeSegment($userId);
        $userDir = $this->vaultRoot().DIRECTORY_SEPARATOR.$safeUser;
        $dir = $userDir.DIRECTORY_SEPARATOR.$this->sanitizeSegment($mediaId);
        $this->crypto->cryptoShredDirectory($dir);

        // Remove user folder if now empty
        if (is_dir($userDir)) {
            $remaining = scandir($userDir);
            if ($remaining !== false && count($remaining) <= 2) {
                @rmdir($userDir);
            }
        }
    }

    public function putUploadFile(string $relativePath, string $bytes): string
    {
        $clean = $this->sanitizeRelativePath($relativePath);
        if ($clean === null) {
            throw new RuntimeException('Invalid upload path.');
        }

        $disk = (string) config('ethernity.uploads.disk', 'uploads');
        Storage::disk($disk)->put($clean, $bytes);

        return '/uploads/'.$clean;
    }

    public function putUploadFromFile(string $relativePath, string $sourceFilePath): string
    {
        $clean = $this->sanitizeRelativePath($relativePath);
        if ($clean === null) {
            throw new RuntimeException('Invalid upload path.');
        }

        $stream = fopen($sourceFilePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read uploaded file stream.');
        }

        try {
            $disk = (string) config('ethernity.uploads.disk', 'uploads');
            Storage::disk($disk)->writeStream($clean, $stream);
        } finally {
            fclose($stream);
        }

        return '/uploads/'.$clean;
    }

    public function uploadFilePath(string $relativePath): ?string
    {
        $clean = $this->sanitizeRelativePath($relativePath);
        if ($clean === null) {
            return null;
        }

        $disk = (string) config('ethernity.uploads.disk', 'uploads');
        if (! Storage::disk($disk)->exists($clean)) {
            return null;
        }

        $resolved = Storage::disk($disk)->path($clean);
        $rootReal = realpath(Storage::disk($disk)->path(''));
        $fileReal = realpath($resolved);

        if ($rootReal === false || $fileReal === false || ! str_starts_with($fileReal, $rootReal.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $fileReal;
    }

    public function deleteUploadByUrl(?string $url): void
    {
        if ($url === null || ! str_starts_with($url, '/uploads/')) {
            return;
        }

        $clean = $this->sanitizeRelativePath(substr($url, strlen('/uploads/')));
        if ($clean === null) {
            return;
        }

        $disk = (string) config('ethernity.uploads.disk', 'uploads');
        if (Storage::disk($disk)->exists($clean)) {
            Storage::disk($disk)->delete($clean);
        }
    }

    public function deleteUploadDirectory(string $relativeDirectory): void
    {
        $clean = $this->sanitizeRelativePath($relativeDirectory);
        if ($clean === null) {
            return;
        }

        $disk = (string) config('ethernity.uploads.disk', 'uploads');
        if (Storage::disk($disk)->directoryExists($clean)) {
            Storage::disk($disk)->deleteDirectory($clean);
        }
    }

    private function vaultRoot(): string
    {
        $disk = (string) config('ethernity.vault.disk', 'vault');
        $root = Storage::disk($disk)->path('');
        if (! is_dir($root)) {
            mkdir($root, 0750, true);
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function sanitizeSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $segment) ?? '';
        if ($clean === '' || $clean === '.' || $clean === '..') {
            throw new RuntimeException('Invalid vault path segment.');
        }

        return $clean;
    }

    private function sanitizeRelativePath(string $relativePath): ?string
    {
        if (str_contains($relativePath, "\0")) {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '') {
            return null;
        }

        $segments = explode('/', $normalized);
        $safe = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '..')) {
                return null;
            }
            $safe[] = $segment;
        }

        return implode('/', $safe);
    }
}
