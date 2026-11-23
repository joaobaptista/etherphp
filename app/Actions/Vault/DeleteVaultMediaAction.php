<?php

namespace App\Actions\Vault;

use App\Models\Memory;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeleteVaultMediaAction
{
    public function __construct(
        protected FindAuthorizedMemoryAction $findMemory,
    ) {}

    public function execute(User $user, string $memoryId): Memory
    {
        $memory = $this->findMemory->execute($user, $memoryId);

        if ($memory->author_id !== $user->id) {
            throw new HttpException(403, 'Apenas o autor pode expurgar esta memória do cofre.');
        }

        // MemoryObserver::deleted performs crypto-shredding of vault files on disk
        $memory->delete();

        return $memory;
    }
}
