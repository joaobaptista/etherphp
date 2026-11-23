<?php

namespace App\Actions\Guardians;

use App\Enums\GuardianStatusEnum;
use App\Models\Guardian;

class UpdateGuardianAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Guardian $guardian, array $attributes): Guardian
    {
        $updates = [];

        if (array_key_exists('name', $attributes)) {
            $updates['name'] = trim((string) $attributes['name']);
        }
        if (array_key_exists('email', $attributes)) {
            $updates['email'] = strtolower(trim((string) $attributes['email']));
        }
        if (array_key_exists('phone', $attributes)) {
            $updates['phone'] = trim((string) $attributes['phone']);
        }
        if (array_key_exists('relation', $attributes) || array_key_exists('relationship', $attributes)) {
            $updates['relation'] = trim((string) ($attributes['relation'] ?? $attributes['relationship'] ?? ''));
        }
        if (array_key_exists('status', $attributes) && is_string($attributes['status'])) {
            $updates['status'] = GuardianStatusEnum::tryFrom($attributes['status']) ?? $guardian->status;
        }
        if (array_key_exists('notes', $attributes)) {
            $updates['notes'] = $attributes['notes'] !== null ? (string) $attributes['notes'] : null;
        }
        if (array_key_exists('canTriggerDelivery', $attributes)) {
            $updates['can_trigger_delivery'] = (bool) $attributes['canTriggerDelivery'];
        }

        if ($updates !== []) {
            $guardian->update($updates);
        }

        return $guardian->refresh();
    }
}
