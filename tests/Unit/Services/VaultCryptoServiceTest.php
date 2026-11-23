<?php

use App\Services\VaultCryptoService;

it('derives unique 256-bit keys per user and encrypts/decrypts AES-256-GCM blocks', function (): void {
    $service = new VaultCryptoService;

    $keyA = $service->deriveUserKey('user-a');
    $keyB = $service->deriveUserKey('user-b');
    expect(strlen($keyA))->toBe(32)
        ->and($keyA)->not->toBe($keyB);

    $plaintext = 'Segredo eterno do cofre Ethernity';
    $ctx = ['userId' => 'user-a', 'mediaId' => 'mem-1', 'variant' => 'master'];

    $cipher = $service->encryptSingleBlock($plaintext, $ctx);
    expect($cipher)->not->toContain($plaintext);

    $decrypted = $service->decryptSingleBlock($cipher, $ctx);
    expect($decrypted)->toBe($plaintext);
});

it('rejects tampered ciphertext or mismatched AAD userId', function (): void {
    $service = new VaultCryptoService;
    $ctx = ['userId' => 'user-a', 'mediaId' => 'mem-1', 'variant' => 'display'];
    $cipher = $service->encryptSingleBlock('conteudo sensivel', $ctx);

    // Tamper last byte
    $tampered = substr($cipher, 0, -1).chr(ord($cipher[-1]) ^ 0xFF);
    expect(fn () => $service->decryptSingleBlock($tampered, $ctx))
        ->toThrow(RuntimeException::class);

    // Wrong AAD userId
    expect(fn () => $service->decryptSingleBlock($cipher, ['userId' => 'attacker', 'mediaId' => 'mem-1', 'variant' => 'display']))
        ->toThrow(RuntimeException::class);
});

it('encrypts chunked AEAD files and decrypts arbitrary HTTP 206 byte ranges', function (): void {
    $service = new VaultCryptoService;
    $inFile = tempnam(sys_get_temp_dir(), 'eth-in-');
    $encFile = tempnam(sys_get_temp_dir(), 'eth-enc-');

    // 150 KB (> 2 chunks of 64 KB)
    $rawPayload = random_bytes(150000);
    file_put_contents($inFile, $rawPayload);

    try {
        $stats = $service->encryptChunkedFile($inFile, $encFile, ['userId' => 'u-1', 'mediaId' => 'mem-vid']);
        expect($stats['totalBytes'])->toBe(150000)
            ->and($stats['totalChunks'])->toBe(3);

        $header = $service->readChunkedHeader($encFile);
        expect($header['totalBytes'])->toBe(150000)
            ->and($header['chunkSize'])->toBe(65536);

        // Read range spanning chunk 0 -> chunk 2
        $range = $service->decryptChunkedRange($encFile, 60000, 140000, ['userId' => 'u-1', 'mediaId' => 'mem-vid']);
        expect($range['buffer'])->toBe(substr($rawPayload, 60000, 80001));

        // Crypto-shred deletes file completely
        $service->cryptoShredFile($encFile);
        expect(is_file($encFile))->toBeFalse();
    } finally {
        @unlink($inFile);
        @unlink($encFile);
    }
});

it('encrypts and decrypts OAuth JSON credentials with AES-256-GCM', function (): void {
    $service = new VaultCryptoService;
    $key = base64_encode(random_bytes(32));
    $payload = ['accessToken' => 'ya29.secret', 'refreshToken' => '1//refresh', 'expiresAt' => 9999999999];

    $sealed = $service->encryptJson($payload, $key);
    expect($sealed)->not->toContain('ya29.secret');

    expect($service->decryptJson($sealed, $key))->toBe($payload);
});
