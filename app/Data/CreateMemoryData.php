<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CreateMemoryData extends Data
{
    public function __construct(
        public string $capsuleId = 'general',
        public string $type = 'IMAGE',
        public ?string $title = null,
        public ?string $content = null,
        public ?string $mediaUrl = null,
        public ?string $occurredAt = null,
        public string $source = 'UPLOAD',
    ) {}
}
