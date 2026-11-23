<?php

namespace App\Http\Resources;

use App\Models\UserVaultKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserVaultKey
 */
class VaultKeyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'configured' => true,
            'version' => (int) $this->version,
            'kdf' => [
                'name' => $this->kdf_name,
                'iterations' => (int) $this->kdf_iterations,
                'salt' => $this->kdf_salt,
            ],
            'passwordWrappedKey' => $this->password_wrapped_key,
            'recoverySalt' => $this->recovery_salt,
            'recoveryWrappedKey' => $this->recovery_wrapped_key,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
