<?php

use App\Contracts\JwtServiceContract;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('manages Zero-Knowledge Vault Keys (create, conflict 409, rotate with proof, reject wrong proof)', function (): void {
    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $this->withToken($token)->getJson('/api/vault/keys')
        ->assertOk()
        ->assertJsonPath('configured', false);

    $passVerifier = base64_encode(random_bytes(32));
    $recVerifier = base64_encode(random_bytes(32));

    $payload = [
        'kdf' => ['iterations' => 600000, 'salt' => base64_encode(random_bytes(16))],
        'passwordWrappedKey' => base64_encode(random_bytes(60)),
        'passwordVerifier' => $passVerifier,
        'recoverySalt' => base64_encode(random_bytes(16)),
        'recoveryWrappedKey' => base64_encode(random_bytes(60)),
        'recoveryVerifier' => $recVerifier,
    ];

    $this->withToken($token)->postJson('/api/vault/keys', $payload)
        ->assertStatus(201)
        ->assertJsonPath('configured', true)
        ->assertJsonPath('kdf.iterations', 600000);

    // Second creation rejected with 409
    $this->withToken($token)->postJson('/api/vault/keys', $payload)->assertStatus(409);

    // Wrong proof rejected with 403
    $this->withToken($token)->putJson('/api/vault/keys', [
        'proof' => ['type' => 'password', 'verifier' => base64_encode(random_bytes(32))],
        'kdf' => ['iterations' => 600000, 'salt' => base64_encode(random_bytes(16))],
        'passwordWrappedKey' => base64_encode(random_bytes(60)),
        'passwordVerifier' => base64_encode(random_bytes(32)),
    ])->assertStatus(403);

    // Valid proof rotates password key
    $newWrapped = base64_encode(random_bytes(60));
    $this->withToken($token)->putJson('/api/vault/keys', [
        'proof' => ['type' => 'password', 'verifier' => $passVerifier],
        'kdf' => ['iterations' => 650000, 'salt' => base64_encode(random_bytes(16))],
        'passwordWrappedKey' => $newWrapped,
        'passwordVerifier' => base64_encode(random_bytes(32)),
    ])->assertOk()->assertJsonPath('passwordWrappedKey', $newWrapped);
});

it('handles Phase 3 client-sealed media (CLIENT_CHUNKED_V1) and legacy server-encrypted WebP vault media', function (): void {
    Storage::fake('vault');
    Storage::fake('uploads');

    $jwt = app(JwtServiceContract::class);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerToken = $jwt->issueForUser($owner);
    $otherToken = $jwt->issueForUser($other);

    // 1. Client-sealed upload (Zero-Knowledge Phase 3)
    $masterBytes = random_bytes(1024);
    $thumbBytes = random_bytes(256);
    $wrappedKey = base64_encode(random_bytes(60));
    $ivs = ['master' => base64_encode(random_bytes(12)), 'thumb' => base64_encode(random_bytes(12))];

    $sealedRes = $this->withToken($ownerToken)->post('/api/vault/media/upload', [
        'clientEncryption' => 'v1',
        'kind' => 'IMAGE',
        'mimeType' => 'image/webp',
        'wrappedKey' => $wrappedKey,
        'chunkSize' => 65536,
        'ivs' => json_encode($ivs, JSON_THROW_ON_ERROR),
        'title' => 'enc:v1:titulo',
        'file' => UploadedFile::fake()->createWithContent('master.cenc', $masterBytes),
        'thumb' => UploadedFile::fake()->createWithContent('thumb.cenc', $thumbBytes),
    ]);

    $sealedRes->assertOk()->assertJsonPath('clientEncrypted', true);
    $mediaId = (string) $sealedRes->json('mediaId');

    $this->withToken($ownerToken)->getJson("/api/vault/media/{$mediaId}/meta")
        ->assertOk()
        ->assertJsonPath('mode', 'client')
        ->assertJsonPath('wrappedKey', $wrappedKey);

    // Anti-IDOR blocks another user
    $this->withToken($otherToken)->getJson("/api/vault/media/{$mediaId}/meta")->assertStatus(403);
    $this->withToken($otherToken)->deleteJson("/api/vault/media/{$mediaId}")->assertStatus(403);

    // Owner fetches encrypted variant bytes untouched
    $download = $this->withToken($ownerToken)->get("/api/vault/media/{$mediaId}?variant=thumb");
    $download->assertOk()->assertHeader('X-Ethernity-Variant', 'thumb');
    expect($download->baseResponse->getFile()->getContent())->toBe($thumbBytes);

    // Export returns 409 for client-sealed files (exported on device)
    $this->withToken($ownerToken)->getJson("/api/vault/media/{$mediaId}/export")->assertStatus(409);

    // Crypto-shred delete
    $this->withToken($ownerToken)->deleteJson("/api/vault/media/{$mediaId}")->assertOk();
    $this->withToken($ownerToken)->getJson("/api/vault/media/{$mediaId}/meta")->assertStatus(404);

    // 2. Legacy server-side multi-tier WebP encryption + print export
    $png = UploadedFile::fake()->image('family.png', 200, 150);
    $legacyRes = $this->withToken($ownerToken)->post('/api/vault/media/upload', [
        'file' => $png,
        'title' => 'Foto Familia',
    ]);
    $legacyRes->assertOk()->assertJsonPath('type', 'IMAGE');
    $legacyMediaId = (string) $legacyRes->json('mediaId');

    $this->withToken($ownerToken)->get("/api/vault/media/{$legacyMediaId}?variant=thumb")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    $this->withToken($ownerToken)->get("/api/vault/media/{$legacyMediaId}/export?format=png")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->withToken($ownerToken)->deleteJson("/api/vault/media/{$legacyMediaId}")->assertOk();
});
