<?php

namespace App\Contracts;

interface VaultCryptoServiceContract
{
    public function deriveUserKey(string $userId, string $customSecret = ''): string;

    /**
     * @param  array{userId?: string, mediaId?: string, variant?: string}  $context
     */
    public function encryptSingleBlock(string $plaintext, array $context = [], ?string $userKey = null): string;

    /**
     * @param  array{userId?: string, mediaId?: string, variant?: string}  $context
     */
    public function decryptSingleBlock(string $encryptedBuffer, array $context = [], ?string $userKey = null): string;

    /**
     * @param  array{userId?: string, mediaId?: string}  $context
     * @return array{totalBytes: int, chunkSize: int, totalChunks: int}
     */
    public function encryptChunkedFile(string $inputFilePath, string $outputEncFilePath, array $context = [], ?string $userKey = null): array;

    /**
     * @return array{version: int, chunkSize: int, totalBytes: int, baseIv: string}
     */
    public function readChunkedHeader(string $encFilePath): array;

    /**
     * @param  array{userId?: string, mediaId?: string}  $context
     * @return array{buffer: string, totalBytes: int, start: int, end: int}
     */
    public function decryptChunkedRange(string $encFilePath, int $startByte, int $endByte, array $context = [], ?string $userKey = null): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encryptJson(array $payload, string $base64Key): string;

    /**
     * @return array<string, mixed>
     */
    public function decryptJson(string $serialized, string $base64Key): array;

    public function cryptoShredFile(string $filePath): void;

    public function cryptoShredDirectory(string $directoryPath): void;
}
