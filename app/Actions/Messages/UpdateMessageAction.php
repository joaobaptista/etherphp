<?php

namespace App\Actions\Messages;

use App\Enums\DeliveryTypeEnum;
use App\Enums\MessageStatusEnum;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UpdateMessageAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Message $message, array $attributes): Message
    {
        $updates = [];

        $map = [
            'title' => 'title',
            'content' => 'content',
            'recipientName' => 'recipient_name',
            'recipient_name' => 'recipient_name',
            'recipientEmail' => 'recipient_email',
            'recipient_email' => 'recipient_email',
            'recipientPhone' => 'recipient_phone',
            'recipient_phone' => 'recipient_phone',
            'deliveryTime' => 'delivery_time',
            'delivery_time' => 'delivery_time',
            'category' => 'category',
        ];

        foreach ($map as $inputKey => $column) {
            if (array_key_exists($inputKey, $attributes)) {
                $updates[$column] = $attributes[$inputKey];
            }
        }

        // Ownership of the capsule is checked by UpdateMessageRequest
        if (array_key_exists('capsuleId', $attributes)) {
            $updates['capsule_id'] = is_string($attributes['capsuleId']) && $attributes['capsuleId'] !== '' ? $attributes['capsuleId'] : null;
        }

        if (array_key_exists('deliveryType', $attributes) || array_key_exists('delivery_type', $attributes)) {
            $raw = (string) ($attributes['deliveryType'] ?? $attributes['delivery_type'] ?? 'date');
            $updates['delivery_type'] = DeliveryTypeEnum::tryFrom($raw) ?? DeliveryTypeEnum::Date;
        }
        if (array_key_exists('deliveryDate', $attributes) || array_key_exists('delivery_date', $attributes)) {
            $rawDate = $attributes['deliveryDate'] ?? $attributes['delivery_date'] ?? null;
            if (is_string($rawDate) && $rawDate !== '') {
                try {
                    $updates['delivery_date'] = Carbon::parse($rawDate);
                } catch (Throwable) {
                    throw new HttpException(422, 'Data de entrega da mensagem inválida.');
                }
            } else {
                $updates['delivery_date'] = null;
            }
        }
        if (array_key_exists('status', $attributes) && is_string($attributes['status'])) {
            $updates['status'] = MessageStatusEnum::tryFrom($attributes['status']) ?? $message->status;
        }
        if (array_key_exists('guardianProtected', $attributes)) {
            $updates['guardian_protected'] = (bool) $attributes['guardianProtected'];
        }
        if (array_key_exists('emailNotification', $attributes)) {
            $updates['email_notification'] = (bool) $attributes['emailNotification'];
        }
        if (array_key_exists('whatsappNotification', $attributes)) {
            $updates['whatsapp_notification'] = (bool) $attributes['whatsappNotification'];
        }
        if (array_key_exists('mediaFiles', $attributes) && is_array($attributes['mediaFiles'])) {
            $updates['media_files'] = $attributes['mediaFiles'];
        }
        if (array_key_exists('active', $attributes)) {
            $updates['active'] = (bool) $attributes['active'];
        }

        if ($updates !== []) {
            $message->update($updates);
        }

        return $message->refresh();
    }
}
