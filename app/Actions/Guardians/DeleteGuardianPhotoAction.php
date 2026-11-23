<?php

namespace App\Actions\Guardians;

use App\Contracts\VaultStorageServiceContract;
use App\Models\Guardian;

class DeleteGuardianPhotoAction
{
    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    public function execute(Guardian $guardian): Guardian
    {
        $previous = $guardian->avatar_url;
        $guardian->update(['avatar_url' => null]);
        $this->storage->deleteUploadByUrl($previous);

        return $guardian->refresh();
    }
}
