<?php

namespace App\Observers;

use App\Contracts\VaultStorageServiceContract;
use App\Models\Capsule;
use App\Models\Memory;

class CapsuleObserver
{
    public function __construct(
        protected VaultStorageServiceContract $storage,
    ) {}

    public function deleting(Capsule $capsule): void
    {
        $memories = Memory::query()
            ->where('capsule_id', $capsule->id)
            ->where('author_id', $capsule->user_id)
            ->get();

        foreach ($memories as $memory) {
            if ($memory->storage_key !== null) {
                $this->storage->shredMedia($capsule->user_id, $memory->storage_key);
            }
            $memory->delete();
        }
    }
}
