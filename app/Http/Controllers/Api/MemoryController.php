<?php

namespace App\Http\Controllers\Api;

use App\Actions\Memories\CreateMemoryAction;
use App\Actions\Memories\DeleteMemoryAction;
use App\Actions\Memories\UpdateMemoryAction;
use App\Data\CreateMemoryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMemoryRequest;
use App\Http\Requests\UpdateMemoryRequest;
use App\Http\Resources\MemoryResource;
use App\Models\Memory;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Memórias', 'Fotos, vídeos, áudios e textos guardados nas cápsulas.', weight: 50)]
class MemoryController extends Controller
{
    /**
     * Listar memórias
     */
    #[QueryParameter('unassigned', 'Só as memórias que ainda não estão em uma cápsula (ex.: fotos do Google Fotos esperando destino).', type: 'bool')]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Memory::query()->where('author_id', $user->id);

        if (is_string($request->query('capsule_id')) && $request->query('capsule_id') !== '') {
            $query->where('capsule_id', $request->query('capsule_id'));
        }
        if (is_string($request->query('type')) && $request->query('type') !== '') {
            $query->where('type', strtoupper($request->query('type')));
        }
        if (is_string($request->query('source')) && $request->query('source') !== '') {
            $query->where('source', strtoupper($request->query('source')));
        }
        // Only memories not yet placed in any capsule (e.g. Google Photos imports waiting for a destination)
        if ($request->boolean('unassigned')) {
            $query->where(fn ($q) => $q->whereNull('capsule_id')->orWhere('capsule_id', 'capsule-default'));
        }

        $memories = $query->orderByDesc('occurred_at')->orderByDesc('created_at')->get();

        return response()->json(MemoryResource::collection($memories)->resolve());
    }

    /**
     * Ver uma memória
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $memory = ctype_digit($id) ? Memory::query()->find((int) $id) : null;

        if ($memory === null) {
            return response()->json(['detail' => 'Memória não encontrada.'], 404);
        }

        if (! Gate::forUser($user)->allows('view', $memory)) {
            return response()->json(['detail' => 'Você não tem permissão para acessar esta memória.'], 403);
        }

        return (new MemoryResource($memory))->response();
    }

    /**
     * Criar uma memória
     */
    public function store(CreateMemoryRequest $request, CreateMemoryAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $body */
        $body = $request->validated();

        $memory = $action->execute($user, new CreateMemoryData(
            capsuleId: (string) ($body['capsuleId'] ?? $body['capsule_id'] ?? 'general'),
            type: (string) ($body['type'] ?? 'IMAGE'),
            title: isset($body['title']) ? (string) $body['title'] : null,
            content: isset($body['content']) ? (string) $body['content'] : (isset($body['story']) ? (string) $body['story'] : null),
            mediaUrl: isset($body['mediaUrl']) ? (string) $body['mediaUrl'] : (isset($body['media_url']) ? (string) $body['media_url'] : (isset($body['img']) ? (string) $body['img'] : null)),
            occurredAt: isset($body['occurredAt']) ? (string) $body['occurredAt'] : (isset($body['occurred_at']) ? (string) $body['occurred_at'] : null),
            source: (string) ($body['source'] ?? 'UPLOAD'),
        ));

        return (new MemoryResource($memory))->response()->setStatusCode(201);
    }

    /**
     * Atualizar uma memória
     *
     * Enviar `capsuleId` **move** a memória para outra cápsula do usuário: ela sai do lugar antigo e não é copiada. Cápsula de outro usuário ou inexistente responde 422.
     */
    public function update(UpdateMemoryRequest $request, string $id, UpdateMemoryAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $memory = ctype_digit($id) ? Memory::query()->find((int) $id) : null;

        if ($memory === null || ! Gate::forUser($user)->allows('update', $memory)) {
            return response()->json(['detail' => 'Memória não encontrada ou acesso não autorizado.'], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $updated = $action->execute($memory, $validated);

        return (new MemoryResource($updated))->response();
    }

    /**
     * Excluir uma memória
     */
    public function destroy(Request $request, string $id, DeleteMemoryAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $memory = ctype_digit($id) ? Memory::query()->find((int) $id) : null;

        if ($memory === null || ! Gate::forUser($user)->allows('delete', $memory)) {
            return response()->json(['detail' => 'Memória não encontrada ou acesso não autorizado.'], 404);
        }

        $action->execute($memory);

        return response()->json([
            'status' => 'success',
            'message' => 'Memória expurgada com sucesso.',
            'id' => $id,
        ]);
    }
}
