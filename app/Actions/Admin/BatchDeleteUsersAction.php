<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\RootAdmin;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BatchDeleteUsersAction
{
    public function __construct(
        protected DeleteUserAction $deleteUser,
    ) {}

    /**
     * @param  list<string>  $userIds
     * @return array{deletedCount: int, skippedCount: int, deletedIds: list<string>}
     */
    public function execute(User $actor, array $userIds): array
    {
        $candidates = User::query()->whereIn('id', $userIds)->get();
        $deletedIds = [];
        $skippedCount = 0;

        foreach ($candidates as $candidate) {
            $isProtected = RootAdmin::isRootEmail($candidate->email)
                || $candidate->is_admin
                || $candidate->id === $actor->id;

            if ($isProtected) {
                $skippedCount++;

                continue;
            }

            $this->deleteUser->execute($actor, $candidate->id);
            $deletedIds[] = $candidate->id;
        }

        if ($deletedIds === []) {
            throw new HttpException(400, 'Nenhum usuário elegível para exclusão em lote (administradores e sua própria conta são protegidos).');
        }

        return [
            'deletedCount' => count($deletedIds),
            'skippedCount' => $skippedCount,
            'deletedIds' => $deletedIds,
        ];
    }
}
