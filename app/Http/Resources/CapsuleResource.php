<?php

namespace App\Http\Resources;

use App\Enums\CapsuleStatusEnum;
use App\Enums\DeliveryTypeEnum;
use App\Models\Capsule;
use App\Models\CapsuleGuardian;
use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Capsule
 */
class CapsuleResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        /** @var array<string, mixed> $recipientData */
        $recipientData = is_array($this->recipient) ? $this->recipient : ['name' => 'Destinatário Especial'];

        $recipientName = (string) ($recipientData['name'] ?? $recipientData['recipient'] ?? 'Destinatário Especial');
        $recipientAvatar = $recipientData['avatar'] ?? $recipientData['recipientAvatar'] ?? $metadata['avatar'] ?? null;

        $delivery = $this->delivery_type;
        $status = $this->status;

        if ($delivery === DeliveryTypeEnum::Posthumous) {
            $triggerLabel = 'Liberação Póstuma (Guardião Responsável)';
        } elseif ($this->scheduled_date !== null) {
            $localized = $this->scheduled_date->copy();
            $localized->locale('pt_BR');
            $triggerLabel = $localized->translatedFormat('j \d\e F \d\e Y');
        } else {
            $triggerLabel = 'Data Definida';
        }

        $statusText = match (true) {
            $status === CapsuleStatusEnum::Unlocked => 'Cápsula Desbloqueada',
            $status === CapsuleStatusEnum::Archived => 'Cápsula Arquivada',
            $delivery === DeliveryTypeEnum::Posthumous => 'Sob Guarda do Guardião',
            default => 'Bloqueada até a data',
        };

        $sealColor = match ($this->emotion) {
            'gratitude' => '#4CAF50',
            'wisdom' => '#7E57C2',
            default => '#F05A47',
        };

        $guardians = $this->relationLoaded('capsuleGuardians')
            ? $this->capsuleGuardians->map(static fn (CapsuleGuardian $g): string => $g->guardian_email)->values()->all()
            : [];

        $photosCount = $this->relationLoaded('memories')
            ? $this->memories->count()
            : (int) ($this->getAttribute('memories_count') ?? 0);

        $data = [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'recipient' => $recipientName,
            'recipientData' => $recipientData,
            'avatar' => $recipientAvatar,
            'recipientAvatar' => $recipientAvatar,
            'metadata' => $metadata,
            'deliveryType' => $delivery->value,
            'delivery_type' => $delivery->value,
            'scheduledDate' => $this->scheduled_date?->toIso8601String(),
            'scheduled_date' => $this->scheduled_date?->toIso8601String(),
            'triggerLabel' => $triggerLabel,
            'emotion' => $this->emotion !== '' ? $this->emotion : 'love',
            'status' => $status->value,
            'statusText' => $statusText,
            'sealColor' => $sealColor,
            'videoDurationSeconds' => (int) $this->video_duration_seconds,
            'videoBlobUrl' => $this->video_blob_url,
            'videoSizeMb' => $this->video_size_mb,
            'isEncrypted' => (bool) $this->is_encrypted,
            'guardians' => $guardians,
            'photosCount' => $photosCount,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->relationLoaded('memories')) {
            $data['memories'] = $this->memories->map(static fn (Memory $m): array => [
                'id' => $m->id,
                'memoryId' => $m->id,
                'mediaId' => $m->storage_key !== null ? basename($m->storage_key) : null,
                'type' => $m->type->value,
                'title' => $m->title,
                'story' => $m->content ?? '',
                'content' => $m->content ?? '',
                'media_url' => $m->media_url,
                'mediaUrl' => $m->media_url,
                'mimeType' => $m->mime_type,
                'durationSeconds' => $m->media_duration_seconds,
                'occurredAt' => $m->occurred_at?->toIso8601String(),
                'createdAt' => $m->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }
}
