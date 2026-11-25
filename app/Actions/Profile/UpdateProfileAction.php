<?php

namespace App\Actions\Profile;

use App\Data\UpdateProfileData;
use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UpdateProfileAction
{
    public function execute(User $user, UpdateProfileData $data): User
    {
        $updates = [];

        if ($data->email !== null && trim($data->email) !== '') {
            $newEmail = strtolower(trim($data->email));
            if ($newEmail !== strtolower($user->email)) {
                $exists = User::query()
                    ->where('email', $newEmail)
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($exists) {
                    throw new HttpException(409, 'Este e-mail já está em uso por outra conta.');
                }

                // Switching to a root admin e-mail would make this account root
                if (RootAdmin::isRootEmail($newEmail)) {
                    throw new HttpException(403, 'Este e-mail é reservado e não pode ser usado nesta conta.');
                }

                $updates['email'] = $newEmail;
            }
        }

        if ($data->name !== null) {
            $updates['name'] = trim($data->name);
        }

        if ($data->phone !== null) {
            $updates['phone'] = trim($data->phone);
        }

        if ($data->password !== null && trim($data->password) !== '') {
            $updates['password_hash'] = Hash::make(trim($data->password));
        }

        if ($data->avatarUrl !== null) {
            $updates['avatar_url'] = $data->avatarUrl;
        }

        if ($updates !== []) {
            $user->update($updates);
        }

        return $user->refresh();
    }
}
