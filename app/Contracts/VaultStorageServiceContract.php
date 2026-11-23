<?php

namespace App\Contracts;

interface VaultStorageServiceContract
{
    public function mediaDirectory(string $userId, string $mediaId): string;

    public function mediaFilePath(string $userId, string $mediaId, string $filename): string;

    public function putMediaBytes(string $userId, string $mediaId, string $filename, string $bytes): string;

    public function putMediaFromFile(string $userId, string $mediaId, string $filename, string $sourceFilePath): string;

    public function getMediaBytes(string $userId, string $mediaId, string $filename): ?string;

    public function mediaFileExists(string $userId, string $mediaId, string $filename): bool;

    public function shredMedia(string $userId, ?string $storageKey): void;

    public function putUploadFile(string $relativePath, string $bytes): string;

    public function putUploadFromFile(string $relativePath, string $sourceFilePath): string;

    public function uploadFilePath(string $relativePath): ?string;

    public function deleteUploadByUrl(?string $url): void;

    public function deleteUploadDirectory(string $relativeDirectory): void;
}
