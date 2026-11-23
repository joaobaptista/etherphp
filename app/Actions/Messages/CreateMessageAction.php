<?php

namespace App\Actions\Messages;

use App\Data\CreateMessageData;
use App\Enums\DeliveryTypeEnum;
use App\Enums\MessageStatusEnum;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CreateMessageAction
{
    public function execute(User $user, CreateMessageData $data): Message
    {
        $title = trim($data->title);
        if ($title === '') {
            throw new HttpException(400, 'O título da mensagem é obrigatório.');
        }

        $delivery = DeliveryTypeEnum::tryFrom($data->deliveryType) ?? DeliveryTypeEnum::Date;
        $status = MessageStatusEnum::tryFrom($data->status) ?? MessageStatusEnum::Scheduled;
        $deliveryDate = null;
        if ($delivery === DeliveryTypeEnum::Date && $data->deliveryDate !== null && $data->deliveryDate !== '') {
            try {
                $deliveryDate = Carbon::parse($data->deliveryDate);
            } catch (Throwable) {
                throw new HttpException(422, 'Data de entrega da mensagem inválida.');
            }
        }

        return Message::query()->create([
            'id' => 'msg-'.Str::uuid(),
            'user_id' => $user->id,
            'title' => $title,
            'content' => $data->content,
            'recipient_name' => $data->recipientName ?? '',
            'recipient_email' => $data->recipientEmail ?? '',
            'recipient_phone' => $data->recipientPhone ?? '',
            'delivery_type' => $delivery,
            'delivery_date' => $deliveryDate,
            'delivery_time' => $data->deliveryTime !== '' ? $data->deliveryTime : '09:00',
            'guardian_protected' => $data->guardianProtected,
            'email_notification' => $data->emailNotification,
            'whatsapp_notification' => $data->whatsappNotification,
            'media_files' => $data->mediaFiles,
            'category' => $data->category !== '' ? $data->category : 'Cartas de Amor',
            'status' => $status,
            'is_encrypted' => true,
            'active' => true,
        ]);
    }
}
