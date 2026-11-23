<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class RotateVaultKeysData extends Data
{
    public function __construct(
        public string $proofType,
        public string $proofVerifier,
        public ?int $kdfIterations = null,
        public ?string $kdfSalt = null,
        public ?string $passwordWrappedKey = null,
        public ?string $passwordVerifier = null,
        public ?string $recoverySalt = null,
        public ?string $recoveryWrappedKey = null,
        public ?string $recoveryVerifier = null,
    ) {}
}
