<?php

namespace App\Actions\Memories;

use App\Models\Capsule;
use App\Models\Memory;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UpdateMemoryAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Memory $memory, array $attributes): Memory
    {
        $updates = [];

        if (array_key_exists('title', $attributes)) {
            $updates['title'] = $attributes['title'] !== null ? (string) $attributes['title'] : null;
        }
        if (array_key_exists('content', $attributes)) {
            $updates['content'] = $attributes['content'] !== null ? (string) $attributes['content'] : null;
        } elseif (array_key_exists('story', $attributes)) {
            $updates['content'] = $attributes['story'] !== null ? (string) $attributes['story'] : null;
        }
        if (array_key_exists('occurredAt', $attributes) || array_key_exists('occurred_at', $attributes)) {
            $rawDate = $attributes['occurredAt'] ?? $attributes['occurred_at'] ?? null;
            if (is_string($rawDate) && $rawDate !== '') {
                try {
                    $updates['occurred_at'] = Carbon::parse($rawDate);
                } catch (Throwable) {
                    throw new HttpException(422, 'Data de ocorrência inválida.');
                }
            }
        }

        // Moves the memory (e.g. a photo imported from Google Photos) into one of the
        // author's capsules. Only the author's own capsules (or the default bucket) are allowed.
        if (array_key_exists('capsuleId', $attributes) && is_string($attributes['capsuleId'])) {
            $capsuleId = trim($attributes['capsuleId']);
            $ownsCapsule = $capsuleId === 'capsule-default' || Capsule::query()
                ->whereKey($capsuleId)
                ->where('user_id', $memory->author_id)
                ->exists();
            if (! $ownsCapsule) {
                throw new HttpException(422, 'Cápsula não encontrada.');
            }
            $updates['capsule_id'] = $capsuleId;
        }

        if ($updates !== []) {
            $memory->update($updates);
        }

        return $memory->refresh();
    }
}
