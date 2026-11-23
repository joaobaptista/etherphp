<?php

use App\Contracts\JwtServiceContract;
use App\Models\Capsule;
use App\Models\Message;
use App\Models\User;

function messageCapsuleFor(User $user, string $id): Capsule
{
    return Capsule::query()->create(['id' => $id, 'user_id' => $user->id, 'title' => 'Cápsula', 'recipient' => ['name' => 'João']]);
}

function messagePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Carta para o João',
        'content' => '<p>Oi, João.</p>',
        'recipientName' => 'João',
        'deliveryType' => 'date',
        'deliveryDate' => null,
        'status' => 'draft',
    ], $overrides);
}

it('saves a message inside a capsule in the same request and lists it with the capsule', function (): void {
    $user = User::factory()->create();
    messageCapsuleFor($user, 'cap-joao');
    $token = app(JwtServiceContract::class)->issueForUser($user);

    $id = $this->withToken($token)->postJson('/api/messages', messagePayload(['capsuleId' => 'cap-joao']))
        ->assertCreated()
        ->assertJsonPath('capsuleId', 'cap-joao')
        ->json('id');

    expect(Message::query()->find($id)->capsule_id)->toBe('cap-joao');

    // Step 2 (delivery) keeps the link
    $this->withToken($token)->putJson("/api/messages/{$id}", ['status' => 'scheduled', 'deliveryDate' => '2027-03-10'])
        ->assertOk()->assertJsonPath('capsuleId', 'cap-joao');

    $this->withToken($token)->getJson('/api/messages?capsuleId=cap-joao')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);

    $this->withToken($token)->getJson('/api/capsules')->assertOk()
        ->assertJsonPath('0.messages.0.id', $id)
        ->assertJsonPath('0.messages.0.status', 'scheduled')
        ->assertJsonPath('0.messages.0.deliveryDate', '2027-03-10');
    $this->withToken($token)->getJson('/api/capsules/cap-joao')->assertOk()->assertJsonPath('messages.0.id', $id);
});

it('refuses a capsule of another user or one that does not exist, without saving anything', function (): void {
    $owner = User::factory()->create();
    messageCapsuleFor(User::factory()->create(), 'cap-alheia');
    $token = app(JwtServiceContract::class)->issueForUser($owner);

    $this->withToken($token)->postJson('/api/messages', messagePayload(['capsuleId' => 'cap-alheia']))
        ->assertStatus(422)->assertJsonPath('detail', 'Cápsula não encontrada. Abra a cápsula de novo e tente salvar outra vez.');
    $this->withToken($token)->postJson('/api/messages', messagePayload(['capsuleId' => 'cap-inexistente']))->assertStatus(422);

    expect(Message::query()->count())->toBe(0);
});

it('moves a message into a capsule, or out of it', function (): void {
    $user = User::factory()->create();
    messageCapsuleFor($user, 'cap-ana');
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $id = $this->withToken($token)->postJson('/api/messages', messagePayload())->assertCreated()->assertJsonPath('capsuleId', null)->json('id');

    $this->withToken($token)->putJson("/api/messages/{$id}", ['capsuleId' => 'cap-ana'])->assertOk()->assertJsonPath('capsuleId', 'cap-ana');
    $this->withToken($token)->putJson("/api/messages/{$id}", ['capsuleId' => null])->assertOk()->assertJsonPath('capsuleId', null);
});

it('deletes the capsule messages together with the capsule and keeps the loose ones', function (): void {
    $user = User::factory()->create();
    messageCapsuleFor($user, 'cap-bia');
    $token = app(JwtServiceContract::class)->issueForUser($user);
    $inCapsule = $this->withToken($token)->postJson('/api/messages', messagePayload(['capsuleId' => 'cap-bia']))->json('id');
    $loose = $this->withToken($token)->postJson('/api/messages', messagePayload())->json('id');

    $this->withToken($token)->deleteJson('/api/capsules/cap-bia')->assertOk();

    expect(Message::query()->find($inCapsule))->toBeNull()
        ->and(Message::query()->find($loose))->not->toBeNull();
});
