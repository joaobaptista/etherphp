<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class UpsertCapsuleData extends Data
{
    /**
     * @param  array<string, mixed>|string|null  $recipient
     * @param  array<string, mixed>|null  $metadata
     * @param  list<string>  $guardians
     * @param  list<array<string, mixed>>  $memories
     */
    public function __construct(
        public string $title,
        public ?string $id = null,
        public array|string|null $recipient = null,
        public string $deliveryType = 'date',
        public ?string $scheduledDate = null,
        public string $emotion = 'love',
        public string $status = 'LOCKED',
        public ?array $metadata = null,
        public int $videoDurationSeconds = 0,
        public ?string $videoBlobUrl = null,
        public ?float $videoSizeMb = null,
        public bool $isEncrypted = true,
        public ?string $encryptionHash = null,
        public array $guardians = [],
        public array $memories = [],
    ) {}
}
