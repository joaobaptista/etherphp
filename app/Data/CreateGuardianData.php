<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CreateGuardianData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone = '',
        public string $relation = '',
        public ?string $notes = null,
        public bool $canTriggerDelivery = true,
        public ?string $id = null,
    ) {}
}
