<?php

namespace App\Services;

use App\Contracts\VaultCryptoServiceContract;
use RuntimeException;

class VaultCryptoService implements VaultCryptoServiceContract
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    private const DEFAULT_CHUNK_SIZE = 65536;

    private const HEADER_MAGIC = 'EVLT';

    private const HEADER_SIZE = 29;

    public function deriveUserKey(string $userId, string $customSecret = ''): string
    {
        $secret = $customSecret !== '' ? $customSecret : $this->masterSecret();
        $salt = 'ethernity-vault-salt-'.$userId;

        return hash_pbkdf2('sha256', $secret, $salt, 100000, 32, true);
    }

    /**
     * Format: [12 bytes IV] + [16 bytes TAG] + [Ciphertext]
     *
     * @param  array{userId?: string, mediaId?: string, variant?: string}  $context
     */
    public function encryptSingleBlock(string $plaintext, array $context = [], ?string $userKey = null): string
    {
        $key = $userKey ?? $this->deriveUserKey($context['userId'] ?? 'system');
        $iv = random_bytes(self::IV_LENGTH);
        $aad = json_encode([
            'userId' => $context['userId'] ?? '',
            'mediaId' => $context['mediaId'] ?? '',
            'variant' => $context['variant'] ?? 'default',
        ], JSON_THROW_ON_ERROR);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_LENGTH,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Failed to encrypt single-block payload.');
        }

        return $iv.$tag.$ciphertext;
    }

    /**
     * @param  array{userId?: string, mediaId?: string, variant?: string}  $context
     */
    public function decryptSingleBlock(string $encryptedBuffer, array $context = [], ?string $userKey = null): string
    {
        if (strlen($encryptedBuffer) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Encrypted payload is invalid or truncated.');
        }

        $key = $userKey ?? $this->deriveUserKey($context['userId'] ?? 'system');
        $iv = substr($encryptedBuffer, 0, self::IV_LENGTH);
        $tag = substr($encryptedBuffer, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($encryptedBuffer, self::IV_LENGTH + self::TAG_LENGTH);

        $aad = json_encode([
            'userId' => $context['userId'] ?? '',
            'mediaId' => $context['mediaId'] ?? '',
            'variant' => $context['variant'] ?? 'default',
        ], JSON_THROW_ON_ERROR);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
        );

        if ($plaintext === false) {
            throw new RuntimeException('AES-256-GCM authentication failed (tampered data or mismatched AAD).');
        }

        return $plaintext;
    }

    /**
     * @param  array{userId?: string, mediaId?: string}  $context
     * @return array{totalBytes: int, chunkSize: int, totalChunks: int}
     */
    public function encryptChunkedFile(
        string $inputFilePath,
        string $outputEncFilePath,
        array $context = [],
        ?string $userKey = null,
    ): array {
        $key = $userKey ?? $this->deriveUserKey($context['userId'] ?? 'system');
        $baseIv = random_bytes(self::IV_LENGTH);
        $totalBytes = (int) filesize($inputFilePath);
        $chunkSize = self::DEFAULT_CHUNK_SIZE;
        $totalChunks = max(1, (int) ceil($totalBytes / $chunkSize));

        $in = fopen($inputFilePath, 'rb');
        $out = fopen($outputEncFilePath, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            throw new RuntimeException('Unable to open files for chunked AEAD encryption.');
        }

        try {
            // Header: [MAGIC 4] + [VER 1] + [CHUNK_SIZE 4 BE] + [TOTAL_BYTES 8 BE] + [BASE_IV 12] = 29 bytes
            $header = self::HEADER_MAGIC.pack('CNJ', 1, $chunkSize, $totalBytes).$baseIv;
            fwrite($out, $header);

            for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
                $chunkData = $totalBytes > 0 ? (string) fread($in, $chunkSize) : '';
                $chunkIv = $this->deriveChunkIv($baseIv, $chunkIndex);
                $aad = json_encode([
                    'userId' => $context['userId'] ?? '',
                    'mediaId' => $context['mediaId'] ?? '',
                    'chunkIndex' => $chunkIndex,
                ], JSON_THROW_ON_ERROR);

                $tag = '';
                $cipherChunk = openssl_encrypt(
                    $chunkData,
                    self::CIPHER,
                    $key,
                    OPENSSL_RAW_DATA,
                    $chunkIv,
                    $tag,
                    $aad,
                    self::TAG_LENGTH,
                );

                if ($cipherChunk === false || strlen($tag) !== self::TAG_LENGTH) {
                    throw new RuntimeException("Failed encrypting chunk {$chunkIndex}.");
                }

                fwrite($out, $tag.$cipherChunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return [
            'totalBytes' => $totalBytes,
            'chunkSize' => $chunkSize,
            'totalChunks' => $totalChunks,
        ];
    }

    /**
     * @return array{version: int, chunkSize: int, totalBytes: int, baseIv: string}
     */
    public function readChunkedHeader(string $encFilePath): array
    {
        $handle = fopen($encFilePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open encrypted chunked file.');
        }

        try {
            $header = (string) fread($handle, self::HEADER_SIZE);
        } finally {
            fclose($handle);
        }

        if (strlen($header) < self::HEADER_SIZE || substr($header, 0, 4) !== self::HEADER_MAGIC) {
            throw new RuntimeException('Invalid Ethernity Vault chunked header.');
        }

        $meta = unpack('Cversion/NchunkSize/JtotalBytes', substr($header, 4, 13));
        if ($meta === false) {
            throw new RuntimeException('Corrupted chunked header metadata.');
        }

        return [
            'version' => (int) $meta['version'],
            'chunkSize' => (int) $meta['chunkSize'],
            'totalBytes' => (int) $meta['totalBytes'],
            'baseIv' => substr($header, 17, self::IV_LENGTH),
        ];
    }

    /**
     * @param  array{userId?: string, mediaId?: string}  $context
     * @return array{buffer: string, totalBytes: int, start: int, end: int}
     */
    public function decryptChunkedRange(
        string $encFilePath,
        int $startByte,
        int $endByte,
        array $context = [],
        ?string $userKey = null,
    ): array {
        $header = $this->readChunkedHeader($encFilePath);
        $totalBytes = $header['totalBytes'];
        $chunkSize = $header['chunkSize'];
        $baseIv = $header['baseIv'];

        if ($totalBytes === 0) {
            return ['buffer' => '', 'totalBytes' => 0, 'start' => 0, 'end' => 0];
        }

        $safeStart = max(0, min($startByte, $totalBytes - 1));
        $safeEnd = max($safeStart, min($endByte, $totalBytes - 1));

        $startChunk = intdiv($safeStart, $chunkSize);
        $endChunk = intdiv($safeEnd, $chunkSize);
        $key = $userKey ?? $this->deriveUserKey($context['userId'] ?? 'system');

        $handle = fopen($encFilePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open encrypted chunked file for range read.');
        }

        $assembled = '';
        $diskChunkStride = self::TAG_LENGTH + $chunkSize;

        try {
            for ($chunkIndex = $startChunk; $chunkIndex <= $endChunk; $chunkIndex++) {
                $expectedPlainLen = min($chunkSize, $totalBytes - ($chunkIndex * $chunkSize));
                $diskOffset = self::HEADER_SIZE + ($chunkIndex * $diskChunkStride);
                fseek($handle, $diskOffset);

                $readLength = max(1, self::TAG_LENGTH + $expectedPlainLen);
                $raw = (string) fread($handle, $readLength);
                if (strlen($raw) < self::TAG_LENGTH) {
                    throw new RuntimeException("Truncated encrypted chunk {$chunkIndex}.");
                }

                $tag = substr($raw, 0, self::TAG_LENGTH);
                $cipherChunk = substr($raw, self::TAG_LENGTH);
                $chunkIv = $this->deriveChunkIv($baseIv, $chunkIndex);
                $aad = json_encode([
                    'userId' => $context['userId'] ?? '',
                    'mediaId' => $context['mediaId'] ?? '',
                    'chunkIndex' => $chunkIndex,
                ], JSON_THROW_ON_ERROR);

                $plainChunk = openssl_decrypt(
                    $cipherChunk,
                    self::CIPHER,
                    $key,
                    OPENSSL_RAW_DATA,
                    $chunkIv,
                    $tag,
                    $aad,
                );

                if ($plainChunk === false) {
                    throw new RuntimeException("Authentication failed on chunk {$chunkIndex}.");
                }

                $assembled .= $plainChunk;
            }
        } finally {
            fclose($handle);
        }

        $offsetInFirstChunk = $safeStart - ($startChunk * $chunkSize);
        $requestedLength = $safeEnd - $safeStart + 1;

        return [
            'buffer' => substr($assembled, $offsetInFirstChunk, $requestedLength),
            'totalBytes' => $totalBytes,
            'start' => $safeStart,
            'end' => $safeEnd,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encryptJson(array $payload, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Token encryption key must be 32 bytes (base64).');
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $encrypted = openssl_encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($encrypted === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Failed to encrypt JSON credentials.');
        }

        return json_encode([
            'iv' => base64_encode($iv),
            'data' => base64_encode($encrypted),
            'tag' => base64_encode($tag),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptJson(string $serialized, string $base64Key): array
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Token encryption key must be 32 bytes (base64).');
        }

        /** @var array{iv?: string, data?: string, tag?: string} $envelope */
        $envelope = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $data = base64_decode((string) ($envelope['data'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);

        if ($iv === false || $data === false || $tag === false) {
            throw new RuntimeException('Invalid encrypted JSON envelope.');
        }

        $plaintext = openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to authenticate encrypted JSON credentials.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function cryptoShredFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            return;
        }

        $size = (int) filesize($filePath);
        if ($size > 0) {
            $handle = fopen($filePath, 'r+b');
            if ($handle !== false) {
                $remaining = $size;
                while ($remaining > 0) {
                    $writeLen = min($remaining, self::DEFAULT_CHUNK_SIZE);
                    fwrite($handle, random_bytes($writeLen));
                    $remaining -= $writeLen;
                }
                fflush($handle);
                fclose($handle);
            }
        }

        @unlink($filePath);
    }

    public function cryptoShredDirectory(string $directoryPath): void
    {
        if (! is_dir($directoryPath)) {
            return;
        }

        $items = scandir($directoryPath);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $directoryPath.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->cryptoShredDirectory($full);
            } else {
                $this->cryptoShredFile($full);
            }
        }

        @rmdir($directoryPath);
    }

    private function deriveChunkIv(string $baseIv, int $chunkIndex): string
    {
        $unpacked = unpack('Ntail', substr($baseIv, 8, 4));
        $tail = $unpacked !== false ? (int) $unpacked['tail'] : 0;
        $xored = ($tail ^ $chunkIndex) & 0xFFFFFFFF;

        return substr($baseIv, 0, 8).pack('N', $xored);
    }

    private function masterSecret(): string
    {
        $secret = (string) config('ethernity.vault.master_key', '');
        if ($secret === '') {
            throw new RuntimeException('VAULT_MASTER_KEY must be configured.');
        }

        return $secret;
    }
}
