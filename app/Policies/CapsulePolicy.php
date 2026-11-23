<?php

namespace App\Policies;

use App\Enums\CapsuleStatusEnum;
use App\Models\Capsule;
use App\Models\User;

class CapsulePolicy
{
    public function view(User $user, Capsule $capsule): bool
    {
        if ($capsule->user_id === $user->id) {
            return true;
        }

        if ($capsule->status !== CapsuleStatusEnum::Unlocked) {
            return false;
        }

        return $capsule->capsuleGuardians()
            ->where('guardian_email', $user->email)
            ->exists();
    }

    public function update(User $user, Capsule $capsule): bool
    {
        return $capsule->user_id === $user->id;
    }

    public function delete(User $user, Capsule $capsule): bool
    {
        return $capsule->user_id === $user->id;
    }
}
