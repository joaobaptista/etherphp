<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class UpdateProfileData extends Data
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $password = null,
        public ?string $avatarUrl = null,
    ) {}
}
