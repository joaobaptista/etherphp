<?php

namespace App\Actions\Memories;

use App\Models\Memory;

class DeleteMemoryAction
{
    public function execute(Memory $memory): void
    {
        // MemoryObserver::deleted performs crypto-shredding of any vault file
        $memory->delete();
    }
}
