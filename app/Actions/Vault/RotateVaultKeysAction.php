<?php

namespace App\Actions\Vault;

use App\Data\RotateVaultKeysData;
use App\Models\User;
use App\Models\UserVaultKey;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RotateVaultKeysAction
{
    public function execute(User $user, RotateVaultKeysData $data): UserVaultKey
    {
        if (! in_array($data->proofType, ['password', 'recovery'], true) || $this->decodedLength($data->proofVerifier) !== 32) {
            throw new HttpException(422, 'Informe a prova da senha ou da chave de recuperação atual.');
        }

        $updatesPassword = $data->passwordWrappedKey !== null;
        $updatesRecovery = $data->recoveryWrappedKey !== null;

        if (! $updatesPassword && ! $updatesRecovery) {
            throw new HttpException(422, 'Nada para atualizar.');
        }

        $minIterations = (int) config('ethernity.vault.min_kdf_iterations', 600000);
        $maxIterations = (int) config('ethernity.vault.max_kdf_iterations', 10000000);

        if ($updatesPassword) {
            if ($data->kdfIterations === null || $data->kdfIterations < $minIterations || $data->kdfIterations > $maxIterations) {
                throw new HttpException(422, 'Parâmetros de derivação inválidos.');
            }
            if ($data->kdfSalt === null || $this->decodedLength($data->kdfSalt) !== 16) {
                throw new HttpException(422, 'Sal da senha inválido.');
            }
            if ($this->decodedLength((string) $data->passwordWrappedKey) !== 60) {
                throw new HttpException(422, 'Chave embrulhada pela senha inválida.');
            }
            if ($data->passwordVerifier === null || $this->decodedLength($data->passwordVerifier) !== 32) {
                throw new HttpException(422, 'Verificador da senha inválido.');
            }
        }

        if ($updatesRecovery) {
            if ($data->recoverySalt === null || $this->decodedLength($data->recoverySalt) !== 16) {
                throw new HttpException(422, 'Sal da recuperação inválido.');
            }
            if ($this->decodedLength((string) $data->recoveryWrappedKey) !== 60) {
                throw new HttpException(422, 'Chave embrulhada pela recuperação inválida.');
            }
            if ($data->recoveryVerifier === null || $this->decodedLength($data->recoveryVerifier) !== 32) {
                throw new HttpException(422, 'Verificador da recuperação inválido.');
            }
        }

        $vaultKey = UserVaultKey::query()->where('user_id', $user->id)->first();
        if ($vaultKey === null) {
            throw new HttpException(404, 'O cofre desta conta ainda não foi criado.');
        }

        $expectedHash = $data->proofType === 'password'
            ? $vaultKey->password_verifier_hash
            : $vaultKey->recovery_verifier_hash;

        $actualHash = $this->hashVerifier($data->proofVerifier);
        if (! hash_equals($expectedHash, $actualHash)) {
            $msg = $data->proofType === 'password'
                ? 'Senha do cofre incorreta.'
                : 'Chave de recuperação incorreta.';
            throw new HttpException(403, $msg);
        }

        $updates = [];
        if ($updatesPassword) {
            $updates['kdf_iterations'] = $data->kdfIterations;
            $updates['kdf_salt'] = $data->kdfSalt;
            $updates['password_wrapped_key'] = $data->passwordWrappedKey;
            $updates['password_verifier_hash'] = $this->hashVerifier((string) $data->passwordVerifier);
        }
        if ($updatesRecovery) {
            $updates['recovery_salt'] = $data->recoverySalt;
            $updates['recovery_wrapped_key'] = $data->recoveryWrappedKey;
            $updates['recovery_verifier_hash'] = $this->hashVerifier((string) $data->recoveryVerifier);
        }

        $vaultKey->update($updates);

        return $vaultKey->refresh();
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
