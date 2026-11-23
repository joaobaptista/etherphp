<?php

namespace App\Http\Resources;

use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Memory
 */
class MemoryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $occurred = $this->occurred_at;
        $dateLabel = 'Recente';
        if ($occurred !== null) {
            $localized = $occurred->copy();
            $localized->locale('pt_BR');
            $dateLabel = $localized->translatedFormat('F \d\e Y');
        }

        return [
            'id' => (string) $this->id,
            'capsuleId' => $this->capsule_id,
            'capsule_id' => $this->capsule_id,
            'authorId' => $this->author_id,
            'author_id' => $this->author_id,
            'type' => $this->type->value,
            'title' => $this->title ?? '',
            'content' => $this->content ?? '',
            'story' => $this->content ?? '',
            'mediaUrl' => $this->media_url,
            'media_url' => $this->media_url,
            'img' => $this->media_url,
            'storageKey' => $this->storage_key,
            'mimeType' => $this->mime_type,
            'fileSizeBytes' => $this->file_size_bytes,
            'isEncrypted' => (bool) $this->is_encrypted,
            'encryptionMode' => $this->encryption_mode->value,
            'variants' => is_array($this->variants_json) ? $this->variants_json : [],
            'source' => $this->source->value,
            'occurredAt' => $occurred?->toIso8601String(),
            'occurred_at' => $occurred?->toIso8601String(),
            'dateLabel' => $dateLabel,
            'year' => $occurred !== null ? $occurred->year : now()->year,
            'createdAt' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
