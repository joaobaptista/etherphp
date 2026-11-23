<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CreateVaultKeysData extends Data
{
    public function __construct(
        public int $kdfIterations,
        public string $kdfSalt,
        public string $passwordWrappedKey,
        public string $passwordVerifier,
        public string $recoverySalt,
        public string $recoveryWrappedKey,
        public string $recoveryVerifier,
    ) {}
}
