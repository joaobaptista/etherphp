<?php

use App\Contracts\GooglePhotosPickerContract;
use App\Contracts\JwtServiceContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Enums\PlanIdEnum;
use App\Jobs\ImportGooglePhotosSessionJob;
use App\Models\GooglePhotosPickerSession;
use App\Models\IntegrationConnection;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config([
        'ethernity.google.client_id' => 'test-client.apps.googleusercontent.com',
        'ethernity.google.client_secret' => 'test-secret',
        'ethernity.google.redirect_uri' => 'http://localhost:3333/api/integrations/google-photos/callback',
        'ethernity.google.token_encryption_key' => base64_encode(str_repeat('k', 32)),
    ]);

    // Fake Google once per test, like the real one: the token exchange issues a token for the
    // user being connected, and userinfo answers by that bearer token. (Http::fake stubs
    // accumulate, so registering new stubs per connection would leak the first user's data.)
    $GLOBALS['gp_profiles'] = [];
    $GLOBALS['gp_next_token'] = null;
    $GLOBALS['gp_token_error'] = null;
    // Picker API: pages of selected items returned by GET /v1/mediaItems (pageToken = page index)
    $GLOBALS['gp_pages'] = [];
    Http::fake([
        'oauth2.googleapis.com/token' => fn () => $GLOBALS['gp_token_error'] !== null
            ? Http::response(['error' => $GLOBALS['gp_token_error'], 'error_description' => 'Token has been expired or revoked.'], 400)
            : Http::response([
                'access_token' => $GLOBALS['gp_next_token'],
                'refresh_token' => 'refresh-'.$GLOBALS['gp_next_token'],
                'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly openid email profile',
            ]),
        'photospicker.googleapis.com/*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/sessions' && $request->method() === 'POST') {
                return Http::response(['id' => 'gsess-1', 'pickerUri' => 'https://photos.google.com/picker/gsess-1', 'pollingConfig' => ['pollInterval' => '2s'], 'mediaItemsSet' => false]);
            }
            if ($path === '/v1/mediaItems') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $page = (int) ($q['pageToken'] ?? 0);
                $body = ['mediaItems' => $GLOBALS['gp_pages'][$page] ?? []];
                if (isset($GLOBALS['gp_pages'][$page + 1])) {
                    $body['nextPageToken'] = (string) ($page + 1);
                }

                return Http::response($body);
            }

            return $request->method() === 'DELETE'
                ? Http::response([])
                : Http::response(['id' => 'gsess-1', 'mediaItemsSet' => true]);
        },
        'lh3.googleusercontent.com/*' => function () {
            ob_start();
            imagejpeg(imagecreatetruecolor(64, 48));

            return Http::response((string) ob_get_clean(), 200, ['Content-Type' => 'image/jpeg']);
        },
        'www.googleapis.com/oauth2/v3/userinfo' => function (Request $request) {
            $token = substr((string) ($request->header('Authorization')[0] ?? ''), 7);
            $profile = $GLOBALS['gp_profiles'][$token] ?? 401;

            return is_int($profile) ? Http::response(['error' => 'failed'], $profile) : Http::response($profile);
        },
    ]);
});

/** Runs the real OAuth callback for $user; Google answers $userInfo (array) or that HTTP error (int). */
function connectGooglePhotos(User $user, array|int $userInfo): TestResponse
{
    $token = 'ya29.test-'.$user->id.'-'.count($GLOBALS['gp_profiles']);
    $GLOBALS['gp_profiles'][$token] = $userInfo;
    $GLOBALS['gp_next_token'] = $token;

    $authUrl = app(GooglePhotosPickerContract::class)->buildAuthorizationUrl($user->id, 'http://localhost:3333')['url'];
    parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $query);

    return test()->get('/api/integrations/google-photos/callback?code=auth-code&state='.urlencode($query['state']));
}

it('stores each user\'s own Google account, never a shared or placeholder one (issue #6)', function (): void {
    $ana = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    $bia = User::factory()->create(['plan_id' => PlanIdEnum::Free]);

    connectGooglePhotos($ana, ['sub' => 'google-sub-ana', 'email' => 'ana@gmail.com', 'name' => 'Ana'])
        ->assertRedirectContains('google_photos_status=connected');
    connectGooglePhotos($bia, ['sub' => 'google-sub-bia', 'email' => 'bia@gmail.com', 'name' => 'Bia'])
        ->assertRedirectContains('google_photos_status=connected');

    $jwt = app(JwtServiceContract::class);
    $this->withToken($jwt->issueForUser($ana))->getJson('/api/integrations/google-photos/status')
        ->assertJsonPath('integration.connected', true)
        ->assertJsonPath('integration.displayName', 'ana@gmail.com');
    $this->withToken($jwt->issueForUser($bia))->getJson('/api/integrations/google-photos/status')
        ->assertJsonPath('integration.displayName', 'bia@gmail.com');

    // A user who never connected is not shown as connected to anyone's account
    $carla = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    $this->withToken($jwt->issueForUser($carla))->getJson('/api/integrations/google-photos/status')
        ->assertJsonPath('integration.connected', false)
        ->assertJsonPath('integration.displayName', null);

    expect(IntegrationConnection::query()->pluck('external_account_id')->all())
        ->toEqualCanonicalizing(['google-sub-ana', 'google-sub-bia']);
});

it('refuses to connect when Google does not return the account, instead of saving a placeholder', function (): void {
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);

    connectGooglePhotos($user, 500)->assertRedirectContains('google_photos_status=error');
    connectGooglePhotos($user, ['name' => 'Sem e-mail'])->assertRedirectContains('google_photos_status=error');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(IntegrationConnection::query()->where('display_name', 'google.user@gmail.com')->exists())->toBeFalse();
});

it('asks the user to connect instead of opening a simulated picker session', function (): void {
    config(['ethernity.security.demo_login_enabled' => true]);
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);

    $response = $this->withToken(app(JwtServiceContract::class)->issueForUser($user))
        ->postJson('/api/integrations/google-photos/sessions', ['capsuleId' => 'cap-1']);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and((string) $response->getContent())->not->toContain('session-sim')
        ->and((string) $response->getContent())->not->toContain('isSimulated');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'photospicker.googleapis.com'));
});

it('revokes the grant at Google and forgets the credentials when the user disconnects', function (): void {
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    connectGooglePhotos($user, ['sub' => 'google-sub-x', 'email' => 'x@gmail.com'])
        ->assertRedirectContains('google_photos_status=connected');

    $this->withToken(app(JwtServiceContract::class)->issueForUser($user))
        ->postJson('/api/integrations/google-photos/disconnect')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && $request['token'] === 'refresh-'.$GLOBALS['gp_next_token']);

    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    expect($connection->credentials_encrypted)->toBeNull()
        ->and($connection->status->value)->toBe('REVOKED');

    $this->withToken(app(JwtServiceContract::class)->issueForUser($user))
        ->getJson('/api/integrations/google-photos/status')
        ->assertJsonPath('integration.connected', false);
});

function pickerItem(string $id, string $type = 'PHOTO'): array
{
    return [
        'id' => $id,
        'type' => $type,
        'mediaFile' => [
            'baseUrl' => 'https://lh3.googleusercontent.com/'.$id,
            'mimeType' => $type === 'VIDEO' ? 'video/mp4' : 'image/jpeg',
            'filename' => $id.($type === 'VIDEO' ? '.mp4' : '.jpg'),
        ],
    ];
}

it('imports every selected photo across pages, skips videos, and never duplicates on re-run', function (): void {
    Storage::fake('vault');
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    connectGooglePhotos($user, ['sub' => 'google-sub-imp', 'email' => 'imp@gmail.com']);
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $GLOBALS['gp_pages'] = [
        [pickerItem('photo-1'), pickerItem('video-1', 'VIDEO')],
        [pickerItem('photo-2')],
    ];

    $session = $this->withToken($token)->postJson('/api/integrations/google-photos/sessions', ['capsuleId' => 'capsule-default'])
        ->assertCreated()->json('session');
    // The picker itself enforces the limit, so nothing selected is silently dropped
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/sessions')
        && $request['pickingConfig']['maxItemCount'] === '50');

    $this->withToken($token)->getJson('/api/integrations/google-photos/sessions/'.$session['id'])
        ->assertJsonPath('session.status', 'SELECTION_READY');

    // phpunit runs the queue synchronously: the job finishes inside this request
    $this->withToken($token)->postJson('/api/integrations/google-photos/sessions/'.$session['id'].'/import')
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED')
        ->assertJsonPath('importedCount', 2)
        ->assertJsonPath('skippedVideos', 1)
        ->assertJsonPath('skippedCount', 0);

    expect(Memory::query()->where('author_id', $user->id)->where('source', 'GOOGLE_PHOTOS')->count())->toBe(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/v1/sessions/gsess-1'));

    // Re-running the import (e.g. a retried job) adds nothing
    $pickerSession = GooglePhotosPickerSession::query()->where('user_id', $user->id)->firstOrFail();
    $pickerSession->update(['status' => 'IMPORTING']);
    app(GooglePhotosPickerContract::class)->runImport($pickerSession->id);
    expect(Memory::query()->where('author_id', $user->id)->count())->toBe(2);

    $this->withToken($token)->getJson('/api/integrations/google-photos/sessions/'.$session['id'])
        ->assertJsonPath('session.status', 'COMPLETED')
        ->assertJsonCount(2, 'session.items');
});

it('runs the import in the background queue and does not start it twice', function (): void {
    Queue::fake();
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    connectGooglePhotos($user, ['sub' => 'google-sub-q', 'email' => 'q@gmail.com']);
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $session = $this->withToken($token)->postJson('/api/integrations/google-photos/sessions')->json('session');
    $this->withToken($token)->getJson('/api/integrations/google-photos/sessions/'.$session['id']);

    $this->withToken($token)->postJson('/api/integrations/google-photos/sessions/'.$session['id'].'/import')
        ->assertOk()->assertJsonPath('status', 'IMPORTING');
    $this->withToken($token)->postJson('/api/integrations/google-photos/sessions/'.$session['id'].'/import')
        ->assertOk()->assertJsonPath('status', 'IMPORTING');

    Queue::assertPushed(ImportGooglePhotosSessionJob::class, 1);
});

it('shows the account as disconnected when the user revoked access at Google', function (): void {
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    connectGooglePhotos($user, ['sub' => 'google-sub-rev', 'email' => 'rev@gmail.com']);
    $token = app(JwtServiceContract::class)->issueForUser($user);

    // Access token expired and the refresh is rejected: the grant was revoked at Google
    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    $crypto = app(VaultCryptoServiceContract::class);
    $key = (string) config('ethernity.google.token_encryption_key');
    $credentials = $crypto->decryptJson((string) $connection->credentials_encrypted, $key);
    $credentials['expiresAt'] = (time() - 60) * 1000;
    $connection->update(['credentials_encrypted' => $crypto->encryptJson($credentials, $key)]);
    $GLOBALS['gp_token_error'] = 'invalid_grant';

    $this->withToken($token)->postJson('/api/integrations/google-photos/sessions')
        ->assertStatus(422)
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Conecte sua conta novamente'));

    $this->withToken($token)->getJson('/api/integrations/google-photos/status')
        ->assertJsonPath('integration.connected', false);
});

it('never imports the same Google photo twice, even in a new picker session', function (): void {
    Storage::fake('vault');
    $user = User::factory()->create(['plan_id' => PlanIdEnum::Free]);
    connectGooglePhotos($user, ['sub' => 'google-sub-dup', 'email' => 'dup@gmail.com']);
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $GLOBALS['gp_pages'] = [[pickerItem('photo-same')]];

    foreach ([1, 2] as $round) {
        $session = $this->withToken($token)->postJson('/api/integrations/google-photos/sessions')->json('session');
        $this->withToken($token)->getJson('/api/integrations/google-photos/sessions/'.$session['id']);
        $result = $this->withToken($token)->postJson('/api/integrations/google-photos/sessions/'.$session['id'].'/import')->json();
        GooglePhotosPickerSession::query()->whereKey($session['id'])->update(['google_session_id' => 'used-'.$round]);

        if ($round === 2) {
            expect($result['importedCount'])->toBe(0)->and($result['alreadyInApp'])->toBe(1);
        }
    }

    expect(Memory::query()->where('author_id', $user->id)->where('source', 'GOOGLE_PHOTOS')->count())->toBe(1);
});
