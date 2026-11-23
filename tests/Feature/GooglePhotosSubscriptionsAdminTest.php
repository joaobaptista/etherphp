<?php

use App\Contracts\JwtServiceContract;
use App\Models\User;

it('enforces JWT authentication on Google Photos Picker endpoints', function (): void {
    $this->getJson('/api/integrations/google-photos/status')->assertStatus(403);
    $this->postJson('/api/integrations/google-photos/authorization-url')->assertStatus(403);
    $this->postJson('/api/integrations/google-photos/sessions')->assertStatus(403);

    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $this->withToken($token)->getJson('/api/integrations/google-photos/status')
        ->assertOk()
        ->assertJsonStructure(['configured', 'connected', 'provider', 'integration']);

    $this->withToken($token)->postJson('/api/integrations/google-photos/disconnect')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('serves subscription plans, current usage, and simulated checkout outside production', function (): void {
    $this->getJson('/api/subscriptions/plans')
        ->assertOk()
        ->assertJsonPath('currency', 'BRL');

    $user = User::factory()->create();
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $this->withToken($token)->getJson('/api/subscriptions/current')
        ->assertOk()
        ->assertJsonStructure(['subscription', 'usage']);

    $this->withToken($token)->postJson('/api/subscriptions/simulate-payment', [
        'planId' => 'family',
        'cycle' => 'yearly',
    ])->assertOk()->assertJsonPath('subscription.planId', 'family');
});

it('protects admin routes, root admins, and self-deletion', function (): void {
    $jwt = app(JwtServiceContract::class);
    $regular = User::factory()->create(['is_admin' => false]);
    $admin = User::factory()->admin()->create();
    $root = User::factory()->admin()->create(['email' => 'vameri@gmail.com']);
    $victim = User::factory()->create(['is_admin' => false]);

    $regularToken = $jwt->issueForUser($regular);
    $adminToken = $jwt->issueForUser($admin);

    // Regular user blocked from admin endpoints
    $this->withToken($regularToken)->getJson('/api/admin/metrics')->assertStatus(403);

    // Admin sees metrics & users
    $this->withToken($adminToken)->getJson('/api/admin/metrics')
        ->assertOk()
        ->assertJsonPath('systemStatus', 'healthy');

    // Root admin cannot be demoted or deleted
    $this->withToken($adminToken)->patchJson("/api/admin/users/{$root->id}/role", ['is_admin' => false])
        ->assertStatus(400);
    $this->withToken($adminToken)->deleteJson("/api/admin/users/{$root->id}")
        ->assertStatus(400);

    // Admin cannot delete themselves
    $this->withToken($adminToken)->deleteJson("/api/admin/users/{$admin->id}")
        ->assertStatus(400);

    // Admin can delete a regular user
    $this->withToken($adminToken)->deleteJson("/api/admin/users/{$victim->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(User::query()->find($victim->id))->toBeNull();
});

it('serves SPA shell on web routes and health check on /health', function (): void {
    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('runtime', 'php-laravel');
});
