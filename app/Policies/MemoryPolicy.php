<?php

namespace App\Policies;

use App\Enums\CapsuleStatusEnum;
use App\Models\Capsule;
use App\Models\Memory;
use App\Models\User;

class MemoryPolicy
{
    public function view(User $user, Memory $memory): bool
    {
        if ($memory->author_id === $user->id) {
            return true;
        }

        return Capsule::query()
            ->where('id', $memory->capsule_id)
            ->where('status', CapsuleStatusEnum::Unlocked->value)
            ->whereHas('capsuleGuardians', fn ($query) => $query->where('guardian_email', $user->email))
            ->exists();
    }

    public function update(User $user, Memory $memory): bool
    {
        return $memory->author_id === $user->id;
    }

    public function delete(User $user, Memory $memory): bool
    {
        return $memory->author_id === $user->id;
    }
}
