<?php

use App\Contracts\JwtServiceContract;
use App\Models\User;

it('manages capsules, memories, messages, guardians and notifications with strict anti-IDOR isolation', function (): void {
    $jwt = app(JwtServiceContract::class);
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $ownerToken = $jwt->issueForUser($owner);
    $intruderToken = $jwt->issueForUser($intruder);

    // 1. Create capsule (idempotent upsert)
    $capRes = $this->withToken($ownerToken)->postJson('/api/capsules', [
        'id' => 'cap-legacy-01',
        'title' => 'Para minha filha',
        'recipient' => ['name' => 'Beatriz'],
        'deliveryType' => 'date',
        'scheduledDate' => '2030-05-01T10:00:00Z',
        'guardians' => ['guardiao@example.com'],
    ]);
    $capRes->assertStatus(201)->assertJsonPath('id', 'cap-legacy-01');

    // Re-send same ID updates instead of duplicating
    $this->withToken($ownerToken)->postJson('/api/capsules', [
        'id' => 'cap-legacy-01',
        'title' => 'Para minha filha (editado)',
    ])->assertOk()->assertJsonPath('title', 'Para minha filha (editado)');

    // Intruder cannot view, overwrite or delete owner's capsule
    $this->withToken($intruderToken)->getJson('/api/capsules/cap-legacy-01')->assertStatus(403);
    $this->withToken($intruderToken)->postJson('/api/capsules', [
        'id' => 'cap-legacy-01',
        'title' => 'Hijack',
    ])->assertStatus(409);
    $this->withToken($intruderToken)->deleteJson('/api/capsules/cap-legacy-01')->assertStatus(403);

    // 2. Memories reject embedded base64/blob outside vault
    $this->withToken($ownerToken)->postJson('/api/memories', [
        'capsuleId' => 'cap-legacy-01',
        'title' => 'Inline',
        'mediaUrl' => 'data:image/png;base64,AAAA',
    ])->assertStatus(422);

    $memRes = $this->withToken($ownerToken)->postJson('/api/memories', [
        'capsuleId' => 'cap-legacy-01',
        'title' => 'Carta de Texto',
        'content' => 'enc:v1:ciphertext',
        'type' => 'TEXT',
    ]);
    $memRes->assertStatus(201);
    $memId = $memRes->json('id');

    $this->withToken($intruderToken)->getJson("/api/memories/{$memId}")->assertStatus(403);

    // 3. Messages CRUD + anti-IDOR
    $msgRes = $this->withToken($ownerToken)->postJson('/api/messages', [
        'title' => 'Carta de 18 anos',
        'content' => 'Com muito amor.',
        'recipientName' => 'Beatriz',
    ]);
    $msgRes->assertStatus(201);
    $msgId = $msgRes->json('id');
    $this->withToken($intruderToken)->getJson("/api/messages/{$msgId}")->assertStatus(404);

    // 4. Guardians CRUD + duplicate check
    $guardRes = $this->withToken($ownerToken)->postJson('/api/guardians', [
        'name' => 'Tio Carlos',
        'email' => 'carlos@example.com',
        'phone' => '11988887777',
        'relation' => 'Irmão',
    ]);
    $guardRes->assertStatus(201);
    $guardId = $guardRes->json('id');

    $this->withToken($ownerToken)->postJson('/api/guardians', [
        'name' => 'Duplicado',
        'email' => 'carlos@example.com',
    ])->assertStatus(400);

    $this->withToken($intruderToken)->getJson("/api/guardians/{$guardId}")->assertStatus(403);

    // 5. Notifications reflect real records and markRead clears unreadCount
    $notif = $this->withToken($ownerToken)->getJson('/api/notifications');
    $notif->assertOk();
    expect($notif->json('unreadCount'))->toBeGreaterThanOrEqual(3);

    $this->withToken($ownerToken)->postJson('/api/notifications/read')->assertOk();
    $afterRead = $this->withToken($ownerToken)->getJson('/api/notifications');
    expect($afterRead->json('unreadCount'))->toBe(0);

    // 6. Definitive purge of capsule cascades its memories
    $this->withToken($ownerToken)->deleteJson('/api/capsules/cap-legacy-01')->assertOk();
    $this->withToken($ownerToken)->getJson("/api/memories/{$memId}")->assertStatus(404);
});
