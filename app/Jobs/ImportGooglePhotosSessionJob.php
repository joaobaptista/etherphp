<?php

namespace App\Jobs;

use App\Contracts\GooglePhotosPickerContract;
use App\Enums\PickerSessionStatusEnum;
use App\Models\GooglePhotosPickerSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Downloads, converts and encrypts the photos chosen in a Google Photos Picker
 * session, outside the HTTP request (an import of many originals takes minutes,
 * more than a web request is allowed to run on most servers).
 */
class ImportGooglePhotosSessionJob implements ShouldQueue
{
    use Queueable;

    /** Seconds the worker may spend on one import (many full-resolution originals). */
    public int $timeout = 1800;

    /** The import is resumable (already-imported items are skipped), but never run twice concurrently. */
    public int $tries = 1;

    public function __construct(
        public int $pickerSessionId,
    ) {}

    public function handle(GooglePhotosPickerContract $picker): void
    {
        $picker->runImport($this->pickerSessionId);
    }

    public function failed(?Throwable $exception): void
    {
        GooglePhotosPickerSession::query()
            ->whereKey($this->pickerSessionId)
            ->where('status', PickerSessionStatusEnum::Importing->value)
            ->update([
                'status' => PickerSessionStatusEnum::Error->value,
                'last_error' => $exception?->getMessage() ?? 'Falha ao importar as fotos.',
            ]);
    }
}
