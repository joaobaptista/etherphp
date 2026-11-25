<?php

namespace App\Actions\Admin;

use App\Contracts\VaultStorageServiceContract;
use App\Models\GooglePhotosPickerSession;
use App\Models\Guardian;
use App\Models\Memory;
use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeleteUserAction
{
    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    public function execute(User $actor, string $targetUserId): User
    {
        $target = User::query()->find($targetUserId);
        if ($target === null) {
            throw new HttpException(404, 'Usuário não encontrado.');
        }

        if (RootAdmin::isRootEmail($target->email)) {
            throw new HttpException(400, 'O administrador principal (Root) é estritamente protegido e não pode ser excluído.');
        }

        if ($actor->id === $target->id || strtolower($actor->email) === strtolower($target->email)) {
            throw new HttpException(400, 'Você não pode excluir sua própria conta nesta lista administrativa.');
        }

        // Removing another admin is as sensitive as revoking the role: root only
        if ($target->is_admin && ! RootAdmin::isRootEmail($actor->email)) {
            throw new HttpException(403, 'Somente o administrador principal (root) pode excluir um administrador.');
        }

        $memories = Memory::query()->where('author_id', $target->id)->get();
        foreach ($memories as $memory) {
            $this->storage->shredMedia($target->id, $memory->storage_key);
        }

        $guardians = Guardian::query()->where('user_id', $target->id)->get();
        foreach ($guardians as $guardian) {
            $this->storage->deleteUploadByUrl($guardian->avatar_url);
        }

        $this->storage->deleteUploadByUrl($target->avatar_url);
        $this->storage->deleteUploadDirectory($target->id);

        DB::transaction(function () use ($target): void {
            Memory::query()->where('author_id', $target->id)->delete();
            GooglePhotosPickerSession::query()->where('user_id', $target->id)->delete();
            $target->delete();
        });

        return $target;
    }
}
