<?php

use App\Contracts\JwtServiceContract;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Capsule;
use App\Models\Memory;
use App\Models\User;

function photoMemoryFor(User $user): Memory
{
    return Memory::query()->create([
        'capsule_id' => 'capsule-default',
        'author_id' => $user->id,
        'type' => MemoryTypeEnum::Image,
        'title' => 'IMG_1.jpg',
        'media_url' => '/api/vault/media/mem-move',
        'storage_key' => "vault/{$user->id}/mem-move",
        'source' => MemorySourceEnum::GooglePhotos,
    ]);
}

function capsuleFor(User $user, string $id): Capsule
{
    return Capsule::query()->create(['id' => $id, 'user_id' => $user->id, 'title' => 'Cápsula', 'recipient' => ['name' => 'Ana']]);
}

it('moves an imported photo into one of the owner\'s capsules', function (): void {
    $user = User::factory()->create();
    $memory = photoMemoryFor($user);
    capsuleFor($user, 'cap-ana');

    $this->withToken(app(JwtServiceContract::class)->issueForUser($user))
        ->putJson("/api/memories/{$memory->id}", ['capsuleId' => 'cap-ana'])
        ->assertOk();

    expect($memory->refresh()->capsule_id)->toBe('cap-ana');
});

it('refuses to move a photo into someone else\'s or a missing capsule', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $memory = photoMemoryFor($owner);
    capsuleFor($other, 'cap-alheia');
    $token = app(JwtServiceContract::class)->issueForUser($owner);

    $this->withToken($token)->putJson("/api/memories/{$memory->id}", ['capsuleId' => 'cap-alheia'])->assertStatus(422);
    $this->withToken($token)->putJson("/api/memories/{$memory->id}", ['capsuleId' => 'cap-inexistente'])->assertStatus(422);

    expect($memory->refresh()->capsule_id)->toBe('capsule-default');
});

it('lists a Google photo while it waits for a capsule and drops it once it has one', function (): void {
    $user = User::factory()->create();
    $memory = photoMemoryFor($user);
    capsuleFor($user, 'cap-bia');
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $this->withToken($token)->getJson('/api/memories?source=GOOGLE_PHOTOS&unassigned=1')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', (string) $memory->id);

    $this->withToken($token)->putJson("/api/memories/{$memory->id}", ['capsuleId' => 'cap-bia'])->assertOk();

    // Moved, not copied: it leaves the "waiting" list and exists once, in the capsule
    $this->withToken($token)->getJson('/api/memories?source=GOOGLE_PHOTOS&unassigned=1')->assertOk()->assertJsonCount(0);
    expect(Memory::query()->where('author_id', $user->id)->count())->toBe(1)
        ->and($memory->refresh()->capsule_id)->toBe('cap-bia');
});
