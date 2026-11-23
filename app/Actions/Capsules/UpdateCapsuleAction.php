<?php

namespace App\Actions\Capsules;

use App\Enums\CapsuleStatusEnum;
use App\Enums\DeliveryTypeEnum;
use App\Models\Capsule;
use App\Models\CapsuleGuardian;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class UpdateCapsuleAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Capsule $capsule, array $attributes): Capsule
    {
        DB::transaction(function () use ($capsule, $attributes): void {
            $updates = [];

            if (array_key_exists('title', $attributes) && is_string($attributes['title'])) {
                $updates['title'] = trim($attributes['title']);
            }
            if (array_key_exists('recipient', $attributes)) {
                $updates['recipient'] = is_array($attributes['recipient'])
                    ? $attributes['recipient']
                    : ['name' => (string) $attributes['recipient']];
            }
            if (array_key_exists('deliveryType', $attributes) || array_key_exists('delivery_type', $attributes)) {
                $rawDelivery = (string) ($attributes['deliveryType'] ?? $attributes['delivery_type'] ?? 'date');
                $updates['delivery_type'] = DeliveryTypeEnum::tryFrom($rawDelivery) ?? DeliveryTypeEnum::Date;
            }
            if (array_key_exists('scheduledDate', $attributes) || array_key_exists('scheduled_date', $attributes)) {
                $rawDate = $attributes['scheduledDate'] ?? $attributes['scheduled_date'] ?? null;
                if (is_string($rawDate) && $rawDate !== '') {
                    try {
                        $updates['scheduled_date'] = Carbon::parse($rawDate);
                    } catch (Throwable) {
                        throw new HttpException(422, 'Data de entrega agendada inválida.');
                    }
                } else {
                    $updates['scheduled_date'] = null;
                }
            }
            if (array_key_exists('emotion', $attributes) && is_string($attributes['emotion'])) {
                $updates['emotion'] = $attributes['emotion'];
            }
            if (array_key_exists('status', $attributes) && is_string($attributes['status'])) {
                $updates['status'] = CapsuleStatusEnum::tryFrom(strtoupper($attributes['status'])) ?? $capsule->status;
            }
            if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
                $updates['metadata'] = array_merge(is_array($capsule->metadata) ? $capsule->metadata : [], $attributes['metadata']);
            }

            if ($updates !== []) {
                $capsule->update($updates);
            }

            if (isset($attributes['guardians']) && is_array($attributes['guardians'])) {
                $capsule->capsuleGuardians()->delete();
                foreach ($attributes['guardians'] as $email) {
                    $clean = strtolower(trim((string) $email));
                    if ($clean === '') {
                        continue;
                    }
                    CapsuleGuardian::query()->create([
                        'id' => 'cg-'.substr((string) Str::uuid(), 0, 12),
                        'capsule_id' => $capsule->id,
                        'guardian_email' => $clean,
                        'created_at' => now(),
                    ]);
                }
            }
        });

        return $capsule->refresh()->load(['capsuleGuardians', 'memories']);
    }
}
