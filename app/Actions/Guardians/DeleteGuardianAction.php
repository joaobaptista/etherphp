<?php

namespace App\Actions\Guardians;

use App\Contracts\VaultStorageServiceContract;
use App\Models\CapsuleGuardian;
use App\Models\Guardian;
use Illuminate\Support\Facades\DB;

class DeleteGuardianAction
{
    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    public function execute(Guardian $guardian): void
    {
        $avatarUrl = $guardian->avatar_url;

        DB::transaction(function () use ($guardian): void {
            CapsuleGuardian::query()
                ->where('guardian_email', $guardian->email)
                ->whereHas('capsule', static fn ($query) => $query->where('user_id', $guardian->user_id))
                ->delete();

            $guardian->delete();
        });

        $this->storage->deleteUploadByUrl($avatarUrl);
    }
}
