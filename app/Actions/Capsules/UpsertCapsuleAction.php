<?php

namespace App\Actions\Capsules;

use App\Data\UpsertCapsuleData;
use App\Enums\CapsuleStatusEnum;
use App\Enums\DeliveryTypeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Capsule;
use App\Models\CapsuleGuardian;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UpsertCapsuleAction
{
    /**
     * @return array{capsule: Capsule, created: bool}
     */
    public function execute(User $user, UpsertCapsuleData $data): array
    {
        $title = trim($data->title);
        if ($title === '') {
            throw new HttpException(400, 'O título da cápsula é obrigatório.');
        }

        $capsuleId = ($data->id !== null && trim($data->id) !== '')
            ? trim($data->id)
            : 'cap-'.substr((string) Str::uuid(), 0, 8);

        $recipient = is_array($data->recipient)
            ? $data->recipient
            : ['name' => is_string($data->recipient) && trim($data->recipient) !== '' ? trim($data->recipient) : 'Destinatário Especial'];

        $delivery = DeliveryTypeEnum::tryFrom($data->deliveryType) ?? DeliveryTypeEnum::Date;
        $scheduledDate = null;
        if ($delivery === DeliveryTypeEnum::Date && $data->scheduledDate !== null && $data->scheduledDate !== '') {
            try {
                $scheduledDate = Carbon::parse($data->scheduledDate);
            } catch (Throwable) {
                throw new HttpException(422, 'Data de entrega agendada inválida.');
            }
        }
        $status = CapsuleStatusEnum::tryFrom(strtoupper($data->status)) ?? CapsuleStatusEnum::Locked;

        $existing = Capsule::query()->find($capsuleId);
        if ($existing !== null) {
            if ($existing->user_id !== $user->id) {
                throw new HttpException(409, 'Identificador de cápsula já utilizado.');
            }

            $existing->update([
                'title' => $title,
                'recipient' => $recipient,
                'delivery_type' => $delivery,
                'scheduled_date' => $scheduledDate,
                'emotion' => $data->emotion !== '' ? $data->emotion : 'love',
                'metadata' => $data->metadata ?? $existing->metadata,
            ]);

            $existing->load(['capsuleGuardians', 'memories']);

            return ['capsule' => $existing, 'created' => false];
        }

        $capsule = DB::transaction(function () use ($user, $capsuleId, $title, $recipient, $delivery, $scheduledDate, $data, $status): Capsule {
            $created = Capsule::query()->create([
                'id' => $capsuleId,
                'user_id' => $user->id,
                'title' => $title,
                'recipient' => $recipient,
                'delivery_type' => $delivery,
                'scheduled_date' => $scheduledDate,
                'emotion' => $data->emotion !== '' ? $data->emotion : 'love',
                'status' => $status,
                'video_duration_seconds' => max(0, $data->videoDurationSeconds),
                'video_blob_url' => $data->videoBlobUrl,
                'video_size_mb' => $data->videoSizeMb,
                'is_encrypted' => $data->isEncrypted,
                'encryption_hash' => $data->encryptionHash,
                'metadata' => $data->metadata,
            ]);

            foreach ($data->guardians as $email) {
                $cleanEmail = strtolower(trim($email));
                if ($cleanEmail === '') {
                    continue;
                }
                CapsuleGuardian::query()->create([
                    'id' => 'cg-'.substr((string) Str::uuid(), 0, 12),
                    'capsule_id' => $created->id,
                    'guardian_email' => $cleanEmail,
                    'created_at' => now(),
                ]);
            }

            foreach ($data->memories as $mem) {
                $mediaUrl = isset($mem['mediaUrl']) ? (string) $mem['mediaUrl'] : (isset($mem['media_url']) ? (string) $mem['media_url'] : null);
                if ($mediaUrl !== null && (! $this->isPersistableMediaUrl($mediaUrl))) {
                    continue;
                }
                Memory::query()->create([
                    'capsule_id' => $created->id,
                    'author_id' => $user->id,
                    'type' => MemoryTypeEnum::tryFrom(strtoupper((string) ($mem['type'] ?? 'IMAGE'))) ?? MemoryTypeEnum::Image,
                    'title' => isset($mem['title']) ? (string) $mem['title'] : null,
                    'content' => isset($mem['content']) ? (string) $mem['content'] : (isset($mem['story']) ? (string) $mem['story'] : ''),
                    'media_url' => $mediaUrl,
                    'is_encrypted' => false,
                    'source' => MemorySourceEnum::Upload,
                    'occurred_at' => now(),
                ]);
            }

            return $created;
        });

        $capsule->load(['capsuleGuardians', 'memories']);

        return ['capsule' => $capsule, 'created' => true];
    }

    private function isPersistableMediaUrl(string $url): bool
    {
        return strlen($url) <= 2048 && preg_match('/^(data|blob):/i', $url) !== 1;
    }
}
