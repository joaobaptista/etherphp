<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\RootAdmin;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UpdateUserRoleAction
{
    public function execute(string $targetUserId, bool $isAdmin): User
    {
        $target = User::query()->find($targetUserId);
        if ($target === null) {
            throw new HttpException(404, 'Usuário não encontrado.');
        }

        if (RootAdmin::isRootEmail($target->email) && ! $isAdmin) {
            throw new HttpException(400, 'O usuário administrador principal não pode ter seus privilégios removidos.');
        }

        $target->update(['is_admin' => $isAdmin]);

        return $target->refresh();
    }
}
