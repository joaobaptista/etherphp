<?php

namespace App\Actions\Capsules;

use App\Models\Capsule;
use Illuminate\Support\Facades\DB;

class DeleteCapsuleAction
{
    public function execute(Capsule $capsule): void
    {
        DB::transaction(function () use ($capsule): void {
            // CapsuleObserver::deleting shreds all linked vault media and deletes memories
            $capsule->delete();
        });
    }
}
