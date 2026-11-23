<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'content' => $this->content ?? '',
            'recipientName' => $this->recipient_name,
            'recipientEmail' => $this->recipient_email,
            'recipientPhone' => $this->recipient_phone,
            'deliveryType' => $this->delivery_type->value,
            'deliveryDate' => $this->delivery_date?->toIso8601String(),
            'deliveryTime' => $this->delivery_time ?? '09:00',
            'guardianProtected' => (bool) $this->guardian_protected,
            'emailNotification' => (bool) $this->email_notification,
            'whatsappNotification' => (bool) $this->whatsapp_notification,
            'mediaFiles' => is_array($this->media_files) ? $this->media_files : [],
            'category' => $this->category ?? 'Cartas de Amor',
            'status' => $this->status->value,
            'isEncrypted' => (bool) $this->is_encrypted,
            'device_id' => $this->device_id,
            'message' => $this->message_text,
            'severity' => $this->severity,
            'active' => (bool) $this->active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
