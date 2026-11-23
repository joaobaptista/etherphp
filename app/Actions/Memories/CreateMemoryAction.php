<?php

namespace App\Actions\Memories;

use App\Data\CreateMemoryData;
use App\Enums\EncryptionModeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use App\Models\Capsule;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CreateMemoryAction
{
    public function execute(User $user, CreateMemoryData $data): Memory
    {
        $title = $data->title !== null ? trim($data->title) : null;
        $content = $data->content !== null ? trim($data->content) : null;
        $mediaUrl = $data->mediaUrl !== null ? trim($data->mediaUrl) : null;

        if (($mediaUrl === null || $mediaUrl === '') && ($content === null || $content === '') && ($title === null || $title === '')) {
            throw new HttpException(400, 'Memória deve possuir título, conteúdo ou arquivo de mídia.');
        }

        if ($mediaUrl !== null && (preg_match('/^(data|blob):/i', $mediaUrl) === 1 || strlen($mediaUrl) > 2048)) {
            throw new HttpException(422, 'Envie o arquivo pelo cofre cifrado.');
        }

        $capsuleId = trim($data->capsuleId) !== '' ? trim($data->capsuleId) : 'general';
        if (! in_array($capsuleId, ['general', 'capsule-default'], true)) {
            $capsule = Capsule::query()->find($capsuleId);
            if ($capsule !== null && $capsule->user_id !== $user->id) {
                throw new HttpException(403, 'Esta cápsula pertence a outro usuário.');
            }
        }

        $occurredAt = now();
        if ($data->occurredAt !== null && trim($data->occurredAt) !== '') {
            try {
                $occurredAt = Carbon::parse($data->occurredAt);
            } catch (Throwable) {
                throw new HttpException(422, 'Data de ocorrência inválida.');
            }
        }

        return Memory::query()->create([
            'capsule_id' => $capsuleId,
            'author_id' => $user->id,
            'type' => MemoryTypeEnum::tryFrom(strtoupper($data->type)) ?? MemoryTypeEnum::Image,
            'title' => $title,
            'content' => $content,
            'media_url' => $mediaUrl,
            'is_encrypted' => false,
            'encryption_mode' => EncryptionModeEnum::SingleBlockGcm,
            'source' => MemorySourceEnum::tryFrom(strtoupper($data->source)) ?? MemorySourceEnum::Upload,
            'occurred_at' => $occurredAt,
        ]);
    }
}
