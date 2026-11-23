<?php

use App\Contracts\JwtServiceContract;
use App\Enums\MemorySourceEnum;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

function iphoneJpeg(int $width = 64): string
{
    ob_start();
    imagejpeg(imagecreatetruecolor($width, 48));

    return (string) ob_get_clean();
}

function putIphonePhoto($test, string $token, string $bytes, ?string $sha = null, array $headers = [], string $site = '')
{
    $sha ??= hash('sha256', $bytes);

    return $test->call('PUT', "{$site}/api/ios/v1/photos/{$sha}.jpg", [], [], [], array_merge([
        'HTTP_AUTHORIZATION' => "Bearer {$token}",
        'CONTENT_TYPE' => 'image/jpeg',
        'HTTP_ACCEPT' => 'application/json',
    ], $headers), $bytes);
}

beforeEach(function (): void {
    Storage::fake('vault');
});

it('logs the iOS app in with the same e-mail and password as the site', function (): void {
    User::factory()->create(['email' => 'ana@example.com', 'password_hash' => Hash::make('segredo-123')]);

    $this->postJson('/api/ios/v1/login', ['email' => 'ana@example.com', 'password' => 'segredo-123'])
        ->assertOk()
        ->assertJsonPath('token_type', 'bearer')
        ->assertJsonPath('user.email', 'ana@example.com')
        ->assertJsonPath('expires_in', 30 * 24 * 60 * 60);

    $this->postJson('/api/ios/v1/login', ['email' => 'ana@example.com', 'password' => 'errada-123'])->assertUnauthorized();
});

it('stores a photo from the iPhone once, waiting for a capsule', function (): void {
    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $jpeg = iphoneJpeg();

    putIphonePhoto($this, $token, $jpeg, headers: ['HTTP_X_ETHERNITY_ASSET_CLOUD_ID' => 'cloud-1', 'HTTP_X_ETHERNITY_SOURCE' => 'picker'])
        ->assertCreated()->assertJsonPath('duplicate', false);
    // The app retries after a timeout: same photo, no copy
    putIphonePhoto($this, $token, $jpeg)->assertOk()->assertJsonPath('duplicate', true);

    $memory = Memory::query()->where('author_id', $user->id)->sole();
    expect($memory->source)->toBe(MemorySourceEnum::Icloud)
        ->and($memory->capsule_id)->toBe('capsule-default')
        ->and($memory->provider_metadata)->toMatchArray(['sha256' => hash('sha256', $jpeg), 'assetCloudId' => 'cloud-1', 'source' => 'picker']);
    // Encrypted thumb, display and master, stored once
    expect(Storage::disk('vault')->allFiles())->toHaveCount(3);
});

it('shows the iPhone photos on the site among the photos waiting for a capsule', function (): void {
    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);
    putIphonePhoto($this, $token, iphoneJpeg())->assertCreated();

    $this->withToken($token)->getJson('/api/memories?source=ICLOUD&unassigned=1')->assertOk()->assertJsonCount(1);
    $this->withToken($token)->getJson('/api/ios/v1/health')->assertOk()->assertJsonPath('photosWaiting', 1);
});

it('rejects a photo whose hash does not match, that is not a JPEG, or without a valid token', function (): void {
    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    putIphonePhoto($this, $token, iphoneJpeg(), str_repeat('a', 64))->assertStatus(422);
    putIphonePhoto($this, $token, 'not a jpeg at all')->assertStatus(415);
    putIphonePhoto($this, 'token-invalido', iphoneJpeg())->assertUnauthorized();

    expect(Memory::query()->count())->toBe(0);
});

it('answers with the full photo address of the host the app called, local or behind a production proxy', function (): void {
    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    // Local: plain HTTP on the Mac's name
    $local = putIphonePhoto($this, $token, iphoneJpeg(64), site: 'http://meu-mac.local:3333')->assertCreated();
    expect($local->json('mediaUrl'))->toStartWith('http://meu-mac.local:3333/api/vault/media/');

    // Production: HTTPS ends at a trusted proxy that forwards plain HTTP to Laravel
    config()->set('trustedproxy.proxies', '*');
    $production = putIphonePhoto($this, $token, iphoneJpeg(80), site: 'http://app.exemplo.com', headers: [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443',
        'REMOTE_ADDR' => '10.0.0.5',
    ])->assertCreated();
    expect($production->json('mediaUrl'))->toStartWith('https://app.exemplo.com/api/vault/media/');
});
