<?php

namespace App\Observers;

use App\Contracts\VaultStorageServiceContract;
use App\Models\Memory;

class MemoryObserver
{
    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    public function deleted(Memory $memory): void
    {
        if ($memory->storage_key !== null) {
            $this->storage->shredMedia($memory->author_id, $memory->storage_key);
        }
    }
}
