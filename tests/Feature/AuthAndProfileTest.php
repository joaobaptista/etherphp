<?php

use App\Contracts\GoogleOAuthServiceContract;
use App\Contracts\JwtServiceContract;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('registers, prevents duplicates, enforces password length, logs in with JWT and serves /api/auth/me', function (): void {
    $short = $this->postJson('/api/register', [
        'name' => 'Ana Maria',
        'email' => 'ana@example.com',
        'phone' => '11999999999',
        'password' => '1234567',
    ]);
    $short->assertStatus(422);

    $reg = $this->postJson('/api/register', [
        'name' => 'Ana Maria',
        'email' => 'ana@example.com',
        'phone' => '11999999999',
        'password' => 'SecretPass123!',
    ]);
    $reg->assertOk()
        ->assertJsonPath('email', 'ana@example.com')
        ->assertJsonPath('plan_id', 'infinity');

    $dup = $this->postJson('/api/register', [
        'name' => 'Ana Clone',
        'email' => 'ana@example.com',
        'phone' => '11999999999',
        'password' => 'SecretPass123!',
    ]);
    $dup->assertStatus(400);

    $badLogin = $this->postJson('/api/login', [
        'email' => 'ana@example.com',
        'password' => 'WrongPassword!',
    ]);
    $badLogin->assertStatus(401);

    $login = $this->postJson('/api/login', [
        'email' => 'ana@example.com',
        'password' => 'SecretPass123!',
    ]);
    $login->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user']);

    $token = (string) $login->json('access_token');

    $this->getJson('/api/auth/me')->assertStatus(403);
    $this->withToken($token)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ana@example.com');
});

it('updates profile, rejects email collision (409) and rejects SVG avatar uploads', function (): void {
    Storage::fake('uploads');

    $userA = User::factory()->create(['email' => 'a@example.com']);
    $userB = User::factory()->create(['email' => 'b@example.com']);
    $tokenA = app(JwtServiceContract::class)->issueForUser($userA);

    $this->withToken($tokenA)->putJson('/api/profile', ['email' => $userB->email])
        ->assertStatus(409);

    $this->withToken($tokenA)->putJson('/api/profile', ['name' => 'Novo Nome'])
        ->assertOk()
        ->assertJsonPath('name', 'Novo Nome');

    $svg = UploadedFile::fake()->createWithContent('xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $this->withToken($tokenA)->post('/api/profile/photo', ['photo' => $svg])
        ->assertStatus(400);
});

it('hardens Google OAuth login: rejects unverified emails, preserves custom profile, returns JWT in fragment', function (): void {
    $jwt = app(JwtServiceContract::class);
    $state = $jwt->signState(['action' => 'auth', 'origin' => 'http://localhost:8000'], 600, 'google-auth-state');

    $mock = Mockery::mock(GoogleOAuthServiceContract::class);
    $mock->shouldReceive('exchangeCode')->andReturn(['access_token' => 'google-access']);
    $mock->shouldReceive('fetchUserInfo')->once()->andReturn([
        'email' => 'unverified@gmail.com',
        'email_verified' => false,
        'name' => 'Fake User',
    ]);
    app()->instance(GoogleOAuthServiceContract::class, $mock);

    $res = $this->get('/api/integrations/google-photos/callback?code=abc&state='.urlencode($state));
    $res->assertRedirect();
    expect((string) $res->headers->get('Location'))->toContain('error=');
    expect(User::query()->where('email', 'unverified@gmail.com')->exists())->toBeFalse();

    // Existing user keeps custom name & custom avatar, receives #token= in fragment
    $existing = User::factory()->create([
        'name' => 'Nome Escolhido',
        'email' => 'verified@gmail.com',
        'avatar_url' => '/uploads/custom-avatar.webp',
    ]);

    $mock2 = Mockery::mock(GoogleOAuthServiceContract::class);
    $mock2->shouldReceive('exchangeCode')->andReturn(['access_token' => 'google-access']);
    $mock2->shouldReceive('fetchUserInfo')->once()->andReturn([
        'email' => 'verified@gmail.com',
        'email_verified' => true,
        'name' => 'Google Name',
        'picture' => 'https://lh3.googleusercontent.com/avatar.jpg',
    ]);
    $mock2->shouldReceive('isGoogleManagedAvatar')->with('/uploads/custom-avatar.webp')->andReturn(false);
    app()->instance(GoogleOAuthServiceContract::class, $mock2);

    $okRes = $this->get('/api/integrations/google-photos/callback?code=abc&state='.urlencode($state));
    $okRes->assertRedirect();
    $location = (string) $okRes->headers->get('Location');
    expect($location)->toContain('/#token=')
        ->and($location)->not->toContain('?token=');

    $existing->refresh();
    expect($existing->name)->toBe('Nome Escolhido')
        ->and($existing->avatar_url)->toBe('/uploads/custom-avatar.webp');
});

it('logs in users migrated from Node.js (bcryptjs $2a$/$2b$ hashes) and upgrades the stored hash', function (string $legacyHash): void {
    $user = User::factory()->create([
        'email' => 'legado@example.com',
        'password_hash' => $legacyHash,
    ]);

    $this->postJson('/api/login', [
        'email' => 'legado@example.com',
        'password' => 'Errada!123',
    ])->assertStatus(401);

    expect($user->refresh()->password_hash)->toBe($legacyHash);

    $this->postJson('/api/login', [
        'email' => 'legado@example.com',
        'password' => 'S3nha!forte',
    ])->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user']);

    $upgraded = (string) $user->refresh()->password_hash;
    expect($upgraded)->toStartWith('$2y$')
        ->and(password_verify('S3nha!forte', $upgraded))->toBeTrue();

    $this->postJson('/api/login', [
        'email' => 'legado@example.com',
        'password' => 'S3nha!forte',
    ])->assertOk();
})->with([
    'bcryptjs $2b$' => '$2b$10$KflcbNvnYsQDMS8/CZuQBO6RFAxeJIpGYhlPGNO1RKJK1crDItAtu',
    'bcryptjs $2a$' => '$2a$10$ik24hXMdLTNz/sqEvMiXxOcMX8WLm5nfBVtnjoqmZrRggsgC9C0gW',
]);

it('rejects login with 401 (not 500) when the stored hash is not bcrypt', function (): void {
    User::factory()->create([
        'email' => 'corrompido@example.com',
        'password_hash' => 'plaintext-or-unknown-hash',
    ]);

    $this->postJson('/api/login', [
        'email' => 'corrompido@example.com',
        'password' => 'plaintext-or-unknown-hash',
    ])->assertStatus(401);
});
