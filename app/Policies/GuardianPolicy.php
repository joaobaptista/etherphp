<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;

class GuardianPolicy
{
    public function view(User $user, Guardian $guardian): bool
    {
        return $guardian->user_id === $user->id;
    }

    public function update(User $user, Guardian $guardian): bool
    {
        return $guardian->user_id === $user->id;
    }

    public function delete(User $user, Guardian $guardian): bool
    {
        return $guardian->user_id === $user->id;
    }
}
