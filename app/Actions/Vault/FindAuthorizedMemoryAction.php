<?php

namespace App\Actions\Vault;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FindAuthorizedMemoryAction
{
    public function execute(User $user, string $memoryId): Memory
    {
        $trimmed = trim($memoryId);
        if ($trimmed === '') {
            throw new HttpException(404, 'Memória não encontrada no cofre.');
        }

        $escapedSuffix = '%/'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $trimmed);

        $query = Memory::query();
        if (ctype_digit($trimmed)) {
            $query->where('id', (int) $trimmed)
                ->orWhereRaw("storage_key LIKE ? ESCAPE '!'", [$escapedSuffix]);
        } else {
            $query->where('storage_key', "vault/{$user->id}/{$trimmed}")
                ->orWhereRaw("storage_key LIKE ? ESCAPE '!'", [$escapedSuffix]);
        }

        $memory = $query->first();
        if ($memory === null) {
            throw new HttpException(404, 'Memória não encontrada no cofre.');
        }

        if (! Gate::forUser($user)->allows('view', $memory)) {
            throw new HttpException(403, 'Acesso não autorizado a esta memória protegida.');
        }

        return $memory;
    }
}
