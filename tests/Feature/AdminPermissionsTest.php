<?php

use App\Contracts\JwtServiceContract;
use App\Models\User;

function tokenFor(User $user): string
{
    return app(JwtServiceContract::class)->issueForUser($user);
}

it('lets only the root admin grant or remove the admin role', function (): void {
    $root = User::factory()->admin()->create(['email' => 'vameri@gmail.com']);
    $admin = User::factory()->admin()->create();
    $regular = User::factory()->create(['is_admin' => false]);
    $target = User::factory()->create(['is_admin' => false]);

    // Regular user and regular admin: 403, nothing changes
    $this->withToken(tokenFor($regular))->patchJson("/api/admin/users/{$target->id}/role", ['is_admin' => true])->assertForbidden();
    $this->withToken(tokenFor($admin))->patchJson("/api/admin/users/{$target->id}/role", ['is_admin' => true])->assertForbidden();
    $this->withToken(tokenFor($regular))->patchJson("/api/admin/users/{$regular->id}/role", ['is_admin' => true])->assertForbidden();
    expect($target->refresh()->is_admin)->toBeFalse()->and($regular->refresh()->is_admin)->toBeFalse();

    // Root grants: the new admin reaches the admin routes
    $targetToken = tokenFor($target);
    $this->withToken($targetToken)->getJson('/api/admin/users')->assertForbidden();
    $this->withToken(tokenFor($root))->patchJson("/api/admin/users/{$target->id}/role", ['is_admin' => true])->assertOk();
    $this->withToken($targetToken)->getJson('/api/admin/users')->assertOk();

    // Root revokes: the same token loses access right away (the role is read from the database on every request)
    $this->withToken(tokenFor($root))->patchJson("/api/admin/users/{$target->id}/role", ['is_admin' => false])->assertOk();
    $this->withToken($targetToken)->getJson('/api/admin/users')->assertForbidden();
});

it('lets only the root admin delete another admin', function (): void {
    $root = User::factory()->admin()->create(['email' => 'vameri@gmail.com']);
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->withToken(tokenFor($admin))->deleteJson("/api/admin/users/{$otherAdmin->id}")->assertForbidden();
    expect(User::query()->find($otherAdmin->id))->not->toBeNull();

    $this->withToken(tokenFor($root))->deleteJson("/api/admin/users/{$otherAdmin->id}")->assertOk();
    expect(User::query()->find($otherAdmin->id))->toBeNull();
});

it('never makes a new or existing user admin through sign-up or profile', function (): void {
    $this->postJson('/api/register', [
        'name' => 'Nova Pessoa',
        'email' => 'nova@example.com',
        'phone' => '11999999999',
        'password' => 'senha-forte-123',
        'is_admin' => true,
        'is_root_admin' => true,
    ])->assertSuccessful();

    $user = User::query()->where('email', 'nova@example.com')->sole();
    expect($user->is_admin)->toBeFalse();

    $token = tokenFor($user);
    $this->withToken($token)->putJson('/api/profile', ['name' => 'Nova', 'is_admin' => true])->assertOk();
    expect($user->refresh()->is_admin)->toBeFalse();

    $this->withToken($token)->getJson('/api/profile')->assertOk()
        ->assertJsonPath('is_admin', 0)
        ->assertJsonPath('is_root_admin', false);
    $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
});

it('never lets anyone take a root e-mail by password sign-up or by changing the profile e-mail', function (): void {
    config()->set('ethernity.security.root_admin_emails', ['root@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Intruso',
        'email' => 'ROOT@example.com',
        'phone' => '11999999999',
        'password' => 'senha-forte-123',
    ])->assertForbidden();
    expect(User::query()->where('email', 'root@example.com')->exists())->toBeFalse();

    $user = User::factory()->create(['email' => 'pessoa@example.com']);
    $this->withToken(tokenFor($user))->putJson('/api/profile', ['email' => 'root@example.com'])->assertForbidden();
    expect($user->refresh()->email)->toBe('pessoa@example.com');
});
