<?php

use App\Contracts\GooglePhotosPickerContract;
use App\Contracts\JwtServiceContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use App\Enums\EncryptionModeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Capsule;
use App\Models\CapsuleGuardian;
use App\Models\Memory;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('scopes guardian deletion so deleting a guardian never strips another user capsule guardian', function (): void {
    $jwt = app(JwtServiceContract::class);
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $tokenA = $jwt->issueForUser($userA);
    $tokenB = $jwt->issueForUser($userB);

    $this->withToken($tokenA)->postJson('/api/capsules', [
        'id' => 'cap-owner-a',
        'title' => 'Cápsula A',
        'guardians' => ['shared@guardian.com'],
    ])->assertStatus(201);

    $this->withToken($tokenB)->postJson('/api/capsules', [
        'id' => 'cap-owner-b',
        'title' => 'Cápsula B',
        'guardians' => ['shared@guardian.com'],
    ])->assertStatus(201);

    $guardA = $this->withToken($tokenA)->postJson('/api/guardians', [
        'name' => 'Guardião Compartilhado',
        'email' => 'shared@guardian.com',
    ])->assertStatus(201);

    $this->withToken($tokenA)->deleteJson('/api/guardians/'.$guardA->json('id'))->assertOk();

    expect(CapsuleGuardian::query()->where('capsule_id', 'cap-owner-a')->count())->toBe(0)
        ->and(CapsuleGuardian::query()->where('capsule_id', 'cap-owner-b')->where('guardian_email', 'shared@guardian.com')->count())->toBe(1);
});

it('protects /uploads/{path} with JWT authentication, ownership checks and path traversal defense', function (): void {
    Storage::fake('uploads');
    $jwt = app(JwtServiceContract::class);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerToken = $jwt->issueForUser($owner);
    $otherToken = $jwt->issueForUser($other);

    $upload = $this->withToken($ownerToken)->post('/api/upload', [
        'file' => UploadedFile::fake()->createWithContent('secret.txt', 'conteudo confidencial'),
    ])->assertOk();

    $url = (string) $upload->json('url');
    expect($url)->toEndWith('.txt');

    // Unauthenticated blocked
    $this->flushHeaders()->get($url)->assertStatus(403);

    // Other user blocked
    $this->flushHeaders()->withToken($otherToken)->get($url)->assertStatus(403);

    // Owner allowed via query token (used by <img>) and Bearer header
    $this->flushHeaders()->get($url.'?token='.urlencode($ownerToken))->assertOk();
    $this->flushHeaders()->withToken($ownerToken)->get($url)->assertOk();

    // Path traversal returns clean 404
    $this->flushHeaders()->withToken($ownerToken)->get('/uploads/....//etc/passwd')->assertStatus(404);
});

it('restricts query-string ?token= to media/upload routes and blocks it on standard API routes', function (): void {
    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $this->getJson('/api/auth/me?token='.urlencode($token))->assertStatus(403);
    $this->withToken($token)->getJson('/api/auth/me')->assertOk();
});

it('blocks cross-user memory injection into another user capsule and handles non-numeric memory IDs safely', function (): void {
    $jwt = app(JwtServiceContract::class);
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $ownerToken = $jwt->issueForUser($owner);
    $attackerToken = $jwt->issueForUser($attacker);

    $this->withToken($ownerToken)->postJson('/api/capsules', [
        'id' => 'cap-private-1',
        'title' => 'Cápsula Privada',
    ])->assertStatus(201);

    $this->withToken($attackerToken)->postJson('/api/memories', [
        'capsuleId' => 'cap-private-1',
        'title' => 'Injeção',
        'content' => 'Invasão',
        'type' => 'TEXT',
    ])->assertStatus(403);

    // Non-numeric ID must return 404 (not PostgreSQL bigint 500 error)
    $this->withToken($ownerToken)->getJson('/api/memories/not-a-numeric-id')->assertStatus(404);
    $this->withToken($ownerToken)->putJson('/api/memories/not-a-numeric-id', ['title' => 'X'])->assertStatus(404);
    $this->withToken($ownerToken)->deleteJson('/api/memories/not-a-numeric-id')->assertStatus(404);
});

it('keeps JWT valid after user updates email in profile', function (): void {
    $user = User::factory()->create(['email' => 'old-email@example.com']);
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $this->withToken($token)->putJson('/api/profile', [
        'email' => 'new-email@example.com',
    ])->assertOk()->assertJsonPath('email', 'new-email@example.com');

    $this->withToken($token)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'new-email@example.com');
});

it('passes capsuleId and sessionId in the right order on nested Google Photos capsule routes', function (): void {
    $jwt = app(JwtServiceContract::class);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerToken = $jwt->issueForUser($owner);
    $otherToken = $jwt->issueForUser($other);

    Capsule::query()->create([
        'id' => 'cap-gp-1',
        'user_id' => $owner->id,
        'title' => 'Viagem',
        'recipient' => ['name' => 'Família'],
    ]);

    $mock = Mockery::mock(GooglePhotosPickerContract::class);
    $mock->shouldReceive('pollSession')
        ->once()
        ->with($owner->id, 'cap-gp-1', 'sess-gp-99')
        ->andReturn(['sessionId' => 'sess-gp-99', 'status' => 'PICKING', 'mediaItemsSet' => false]);
    $mock->shouldReceive('importSession')
        ->once()
        ->with($owner->id, 'cap-gp-1', 'sess-gp-99')
        ->andReturn(['success' => true, 'importedCount' => 2, 'items' => []]);
    app()->instance(GooglePhotosPickerContract::class, $mock);

    $this->withToken($ownerToken)->getJson('/api/capsules/cap-gp-1/google-photos/sessions/sess-gp-99')
        ->assertOk()
        ->assertJsonPath('session.sessionId', 'sess-gp-99');

    $this->withToken($ownerToken)->postJson('/api/capsules/cap-gp-1/google-photos/sessions/sess-gp-99/import')
        ->assertOk()
        ->assertJsonPath('importedCount', 2);

    // Other user blocked from polling/importing against owner's capsule
    $this->withToken($otherToken)->getJson('/api/capsules/cap-gp-1/google-photos/sessions/sess-gp-99')
        ->assertStatus(403);
});

it('sanitizes untrusted Origin header on Google Photos Picker OAuth URL to prevent open redirects', function (): void {
    config()->set('ethernity.google.client_id', 'test-client-id');
    config()->set('ethernity.google.client_secret', 'test-client-secret');
    config()->set('ethernity.google.redirect_uri', 'http://localhost:8000/api/integrations/google-photos/callback');
    config()->set('ethernity.google.token_encryption_key', base64_encode(random_bytes(32)));
    config()->set('ethernity.frontend_url', 'http://localhost:8000');

    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $res = $this->withToken($token)
        ->withHeader('Origin', 'https://evil.attacker.example')
        ->getJson('/api/integrations/google-photos/authorization-url')
        ->assertOk();

    $authUrl = (string) $res->json('authorizationUrl');
    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $query);
    $stateClaims = app(JwtServiceContract::class)->verifyState((string) ($query['state'] ?? ''));

    expect($stateClaims)->not->toBeNull()
        ->and($stateClaims['origin'])->toBe('http://localhost:8000');
});

it('streams complete chunked AEAD file when no Range header is sent, supports suffix ranges and returns 416 out of bounds', function (): void {
    Storage::fake('vault');

    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $crypto = app(VaultCryptoServiceContract::class);
    $storage = app(VaultStorageServiceContract::class);

    $mediaId = 'mem-stream-full-test';
    $rawBytes = random_bytes(204800); // 200 KB (> 128 KB old cap)
    $tmpIn = tempnam(sys_get_temp_dir(), 'eth-stream-in-');
    file_put_contents($tmpIn, $rawBytes);

    try {
        $encPath = $storage->mediaFilePath($user->id, $mediaId, 'video.mp4.enc');
        $crypto->encryptChunkedFile($tmpIn, $encPath, ['userId' => $user->id, 'mediaId' => $mediaId]);
    } finally {
        @unlink($tmpIn);
    }

    Memory::query()->create([
        'capsule_id' => 'capsule-default',
        'author_id' => $user->id,
        'type' => MemoryTypeEnum::Video,
        'title' => 'Video Completo',
        'media_url' => "/api/vault/media/{$mediaId}/stream",
        'storage_key' => "vault/{$user->id}/{$mediaId}",
        'mime_type' => 'video/mp4',
        'file_size_bytes' => strlen($rawBytes),
        'is_encrypted' => true,
        'encryption_mode' => EncryptionModeEnum::ChunkedAead,
        'chunk_size_bytes' => 65536,
        'source' => MemorySourceEnum::Upload,
        'occurred_at' => now(),
    ]);

    // Full 200 stream without Range header
    $full = $this->withToken($token)->get("/api/vault/media/{$mediaId}/stream");
    $full->assertOk()->assertHeader('Content-Length', (string) strlen($rawBytes));
    expect($full->streamedContent())->toBe($rawBytes);

    // Suffix byte range (last 100 bytes)
    $suffix = $this->withToken($token)
        ->withHeader('Range', 'bytes=-100')
        ->get("/api/vault/media/{$mediaId}/stream");
    $suffix->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 204700-204799/204800');
    expect($suffix->getContent())->toBe(substr($rawBytes, -100));

    // Out-of-bounds range returns 416
    $this->withToken($token)
        ->withHeader('Range', 'bytes=999999-')
        ->get("/api/vault/media/{$mediaId}/stream")
        ->assertStatus(416);
});

it('returns 422 instead of 500 for undecodable images, uses proper plan labels and aligns generate-ai contract', function (): void {
    Storage::fake('vault');

    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    // Invalid HEIC bytes return 422
    $badHeic = UploadedFile::fake()->createWithContent('photo.heic', 'not-a-valid-heic-payload');
    $this->withToken($token)->post('/api/vault/media/upload', ['file' => $badHeic])
        ->assertStatus(422);

    // Yearly subscription formats yearly price, preserves PK on renewal, and UserResource shows proper label
    $sub1 = $this->withToken($token)->postJson('/api/subscriptions/simulate-payment', [
        'planId' => 'family',
        'cycle' => 'yearly',
    ])->assertOk()
        ->assertJsonPath('subscription.priceFormatted', 'R$ 299,00');

    $firstSubId = (string) $sub1->json('subscription.id');

    $this->withToken($token)->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('plan_id', 'family')
        ->assertJsonPath('plan', 'Legado Familiar');

    $sub2 = $this->withToken($token)->postJson('/api/subscriptions/simulate-payment', [
        'planId' => 'clinical',
        'cycle' => 'monthly',
    ])->assertOk();

    expect($sub2->json('subscription.id'))->toBe($firstSubId)
        ->and(Subscription::query()->where('user_id', $user->id)->value('price_formatted'))->toBe('R$ 79,90');

    // generate-ai honors prompt + recipientName + tone
    $ai = $this->withToken($token)->postJson('/api/messages/generate-ai', [
        'recipientName' => 'Beatriz',
        'prompt' => 'sua formatura em medicina',
        'tone' => 'gratidao',
    ])->assertOk();

    expect((string) $ai->json('title'))->toContain('Beatriz')
        ->and((string) $ai->json('title'))->toContain('sua formatura em medicina')
        ->and((string) $ai->json('content'))->toContain('agradecer');
});
