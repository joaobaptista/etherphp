<?php

namespace App\Http\Controllers\Api;

use App\Actions\Vault\CreateVaultKeysAction;
use App\Actions\Vault\RotateVaultKeysAction;
use App\Data\CreateVaultKeysData;
use App\Data\RotateVaultKeysData;
use App\Http\Controllers\Controller;
use App\Http\Resources\VaultKeyResource;
use App\Models\User;
use App\Models\UserVaultKey;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Cofre', 'Chaves e mídia criptografada (AES-256-GCM).', weight: 80)]
class VaultKeyController extends Controller
{
    /**
     * Ver as chaves do cofre
     *
     * Devolve só material embrulhado (wrapped). Sem cofre configurado, responde `{ "configured": false }`.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $row = UserVaultKey::query()->where('user_id', $user->id)->first();

        $response = $row !== null
            ? (new VaultKeyResource($row))->response()
            : response()->json(['configured' => false]);

        return $response->header('Cache-Control', 'no-store');
    }

    /**
     * Configurar o cofre
     *
     * Grava a chave mestra embrulhada pela senha e pela chave de recuperação.
     */
    public function store(Request $request, CreateVaultKeysAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $kdf */
        $kdf = is_array($request->input('kdf')) ? $request->input('kdf') : [];

        $vaultKey = $action->execute($user, new CreateVaultKeysData(
            kdfIterations: (int) ($kdf['iterations'] ?? 0),
            kdfSalt: (string) ($kdf['salt'] ?? ''),
            passwordWrappedKey: (string) $request->input('passwordWrappedKey', ''),
            passwordVerifier: (string) $request->input('passwordVerifier', ''),
            recoverySalt: (string) $request->input('recoverySalt', ''),
            recoveryWrappedKey: (string) $request->input('recoveryWrappedKey', ''),
            recoveryVerifier: (string) $request->input('recoveryVerifier', ''),
        ));

        return (new VaultKeyResource($vaultKey))->response()->setStatusCode(201);
    }

    /**
     * Trocar senha ou chave de recuperação do cofre
     *
     * Exige uma prova (`proof`) da senha ou da chave de recuperação atual.
     */
    public function update(Request $request, RotateVaultKeysAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $proof */
        $proof = is_array($request->input('proof')) ? $request->input('proof') : [];
        /** @var array<string, mixed> $kdf */
        $kdf = is_array($request->input('kdf')) ? $request->input('kdf') : [];

        $vaultKey = $action->execute($user, new RotateVaultKeysData(
            proofType: (string) ($proof['type'] ?? ''),
            proofVerifier: (string) ($proof['verifier'] ?? ''),
            kdfIterations: isset($kdf['iterations']) ? (int) $kdf['iterations'] : null,
            kdfSalt: isset($kdf['salt']) ? (string) $kdf['salt'] : null,
            passwordWrappedKey: $request->has('passwordWrappedKey') ? (string) $request->input('passwordWrappedKey') : null,
            passwordVerifier: $request->has('passwordVerifier') ? (string) $request->input('passwordVerifier') : null,
            recoverySalt: $request->has('recoverySalt') ? (string) $request->input('recoverySalt') : null,
            recoveryWrappedKey: $request->has('recoveryWrappedKey') ? (string) $request->input('recoveryWrappedKey') : null,
            recoveryVerifier: $request->has('recoveryVerifier') ? (string) $request->input('recoveryVerifier') : null,
        ));

        return (new VaultKeyResource($vaultKey))->response();
    }
}
