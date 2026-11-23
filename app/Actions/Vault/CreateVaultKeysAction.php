<?php

namespace App\Actions\Vault;

use App\Data\CreateVaultKeysData;
use App\Models\User;
use App\Models\UserVaultKey;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreateVaultKeysAction
{
    public function execute(User $user, CreateVaultKeysData $data): UserVaultKey
    {
        $minIterations = (int) config('ethernity.vault.min_kdf_iterations', 600000);
        $maxIterations = (int) config('ethernity.vault.max_kdf_iterations', 10000000);

        if ($data->kdfIterations < $minIterations || $data->kdfIterations > $maxIterations) {
            throw new HttpException(422, 'Parâmetros de derivação inválidos.');
        }

        if ($this->decodedLength($data->kdfSalt) !== 16) {
            throw new HttpException(422, 'Sal da senha inválido.');
        }
        if ($this->decodedLength($data->passwordWrappedKey) !== 60) {
            throw new HttpException(422, 'Chave embrulhada pela senha inválida.');
        }
        if ($this->decodedLength($data->passwordVerifier) !== 32) {
            throw new HttpException(422, 'Verificador da senha inválido.');
        }
        if ($this->decodedLength($data->recoverySalt) !== 16) {
            throw new HttpException(422, 'Sal da recuperação inválido.');
        }
        if ($this->decodedLength($data->recoveryWrappedKey) !== 60) {
            throw new HttpException(422, 'Chave embrulhada pela recuperação inválida.');
        }
        if ($this->decodedLength($data->recoveryVerifier) !== 32) {
            throw new HttpException(422, 'Verificador da recuperação inválido.');
        }

        if (UserVaultKey::query()->where('user_id', $user->id)->exists()) {
            throw new HttpException(409, 'O cofre desta conta já foi criado.');
        }

        return UserVaultKey::query()->create([
            'user_id' => $user->id,
            'version' => 1,
            'kdf_name' => 'PBKDF2-SHA256',
            'kdf_iterations' => $data->kdfIterations,
            'kdf_salt' => $data->kdfSalt,
            'password_wrapped_key' => $data->passwordWrappedKey,
            'password_verifier_hash' => $this->hashVerifier($data->passwordVerifier),
            'recovery_salt' => $data->recoverySalt,
            'recovery_wrapped_key' => $data->recoveryWrappedKey,
            'recovery_verifier_hash' => $this->hashVerifier($data->recoveryVerifier),
        ]);
    }

    private function decodedLength(string $value): int
    {
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) !== 1) {
            return -1;
        }
        $decoded = base64_decode($value, true);

        return $decoded === false ? -1 : strlen($decoded);
    }

    private function hashVerifier(string $base64Verifier): string
    {
        return hash('sha256', (string) base64_decode($base64Verifier, true));
    }
}
