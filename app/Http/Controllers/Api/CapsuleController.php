<?php

namespace App\Http\Controllers\Api;

use App\Actions\Capsules\DeleteCapsuleAction;
use App\Actions\Capsules\UpdateCapsuleAction;
use App\Actions\Capsules\UpsertCapsuleAction;
use App\Data\UpsertCapsuleData;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCapsuleRequest;
use App\Http\Requests\UpsertCapsuleRequest;
use App\Http\Resources\CapsuleResource;
use App\Models\Capsule;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Cápsulas', 'Cápsulas do tempo do usuário.', weight: 40)]
class CapsuleController extends Controller
{
    /**
     * Listar cápsulas
     *
     * Só as cápsulas do usuário logado, das mais novas para as mais antigas.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $capsules = Capsule::query()
            ->where('user_id', $user->id)
            ->with(['capsuleGuardians', 'messages' => static fn ($query) => $query->latest()])
            ->withCount('memories')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(CapsuleResource::collection($capsules)->resolve());
    }

    /**
     * Ver uma cápsula
     *
     * Inclui as memórias e os guardiões da cápsula.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $capsule = Capsule::query()
            ->with(['capsuleGuardians', 'messages' => static fn ($query) => $query->latest()])
            ->find($id);

        if ($capsule === null) {
            return response()->json(['detail' => 'Cápsula não encontrada.'], 404);
        }

        if (! Gate::forUser($user)->allows('view', $capsule)) {
            return response()->json(['detail' => 'Você não tem permissão para acessar esta cápsula.'], 403);
        }

        $capsule->load(['memories' => static fn ($query) => $query->where('author_id', $capsule->user_id)]);

        return (new CapsuleResource($capsule))->response();
    }

    /**
     * Criar ou salvar uma cápsula
     *
     * Se o `id` enviado já existir e for do usuário, a cápsula é atualizada.
     */
    public function store(UpsertCapsuleRequest $request, UpsertCapsuleAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $body */
        $body = $request->all();

        $result = $action->execute($user, new UpsertCapsuleData(
            title: (string) $request->validated('title'),
            id: isset($body['id']) ? (string) $body['id'] : null,
            recipient: $body['recipient'] ?? null,
            deliveryType: (string) ($body['deliveryType'] ?? $body['delivery_type'] ?? 'date'),
            scheduledDate: isset($body['scheduledDate']) ? (string) $body['scheduledDate'] : (isset($body['scheduled_date']) ? (string) $body['scheduled_date'] : null),
            emotion: (string) ($body['emotion'] ?? 'love'),
            status: (string) ($body['status'] ?? 'LOCKED'),
            metadata: is_array($body['metadata'] ?? null) ? $body['metadata'] : null,
            videoDurationSeconds: (int) ($body['videoDurationSeconds'] ?? $body['video_duration_seconds'] ?? 0),
            videoBlobUrl: isset($body['videoBlobUrl']) ? (string) $body['videoBlobUrl'] : null,
            videoSizeMb: isset($body['videoSizeMb']) ? (float) $body['videoSizeMb'] : null,
            isEncrypted: (bool) ($body['isEncrypted'] ?? true),
            encryptionHash: isset($body['encryptionHash']) ? (string) $body['encryptionHash'] : null,
            guardians: is_array($body['guardians'] ?? null) ? array_values($body['guardians']) : [],
            memories: is_array($body['memories'] ?? null) ? array_values($body['memories']) : [],
        ));

        return (new CapsuleResource($result['capsule']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Atualizar uma cápsula
     */
    public function update(UpdateCapsuleRequest $request, string $id, UpdateCapsuleAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $capsule = Capsule::query()->find($id);

        if ($capsule === null) {
            return response()->json(['detail' => 'Cápsula não encontrada.'], 404);
        }

        if (! Gate::forUser($user)->allows('update', $capsule)) {
            return response()->json(['detail' => 'Você não tem permissão para alterar esta cápsula.'], 403);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $updated = $action->execute($capsule, $validated);

        return (new CapsuleResource($updated))->response();
    }

    /**
     * Excluir uma cápsula
     *
     * A mídia criptografada da cápsula é apagada junto (crypto-shredding).
     */
    public function destroy(Request $request, string $id, DeleteCapsuleAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $capsule = Capsule::query()->find($id);

        if ($capsule === null) {
            return response()->json(['detail' => 'Cápsula não encontrada.'], 404);
        }

        if (! Gate::forUser($user)->allows('delete', $capsule)) {
            return response()->json(['detail' => 'Você não tem permissão para excluir esta cápsula.'], 403);
        }

        $action->execute($capsule);

        return response()->json([
            'status' => 'success',
            'message' => 'Cápsula expurgada definitivamente.',
            'id' => $id,
        ]);
    }
}
