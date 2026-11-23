<?php

namespace App\Http\Controllers\Api;

use App\Actions\Guardians\CreateGuardianAction;
use App\Actions\Guardians\DeleteGuardianAction;
use App\Actions\Guardians\DeleteGuardianPhotoAction;
use App\Actions\Guardians\UpdateGuardianAction;
use App\Actions\Guardians\UploadGuardianPhotoAction;
use App\Data\CreateGuardianData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGuardianRequest;
use App\Http\Requests\UpdateGuardianRequest;
use App\Http\Resources\GuardianResource;
use App\Models\CircleConnection;
use App\Models\Guardian;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Guardiões', 'Pessoas de confiança que recebem ou liberam as cápsulas.', weight: 70)]
class GuardianController extends Controller
{
    /**
     * Listar guardiões
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $guardians = Guardian::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get();

        return response()->json(GuardianResource::collection($guardians)->resolve());
    }

    /**
     * Conexões do círculo de confiança
     */
    public function connections(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $connections = CircleConnection::query()
            ->where('requester_id', $user->id)
            ->orWhere('target_user_id', $user->id)
            ->orWhere('target_email', $user->email)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($connections);
    }

    /**
     * Ver um guardião
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $guardian = Guardian::query()->find($id);

        if ($guardian === null) {
            return response()->json(['detail' => 'Guardião não encontrado.'], 404);
        }

        if (! Gate::forUser($user)->allows('view', $guardian)) {
            return response()->json(['detail' => 'Você não tem permissão para acessar este guardião.'], 403);
        }

        return (new GuardianResource($guardian))->response();
    }

    /**
     * Adicionar um guardião
     */
    public function store(CreateGuardianRequest $request, CreateGuardianAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $v */
        $v = $request->validated();

        $guardian = $action->execute($user, new CreateGuardianData(
            name: (string) $v['name'],
            email: (string) $v['email'],
            phone: (string) ($v['phone'] ?? ''),
            relation: (string) ($v['relation'] ?? $v['relationship'] ?? ''),
            notes: isset($v['notes']) ? (string) $v['notes'] : null,
            canTriggerDelivery: (bool) ($v['canTriggerDelivery'] ?? true),
            id: isset($v['id']) ? (string) $v['id'] : null,
        ));

        return (new GuardianResource($guardian))->response()->setStatusCode(201);
    }

    /**
     * Atualizar um guardião
     */
    public function update(UpdateGuardianRequest $request, string $id, UpdateGuardianAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $guardian = Guardian::query()->find($id);

        if ($guardian === null || ! Gate::forUser($user)->allows('update', $guardian)) {
            return response()->json(['detail' => 'Guardião não encontrado ou acesso não autorizado.'], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        return (new GuardianResource($action->execute($guardian, $validated)))->response();
    }

    /**
     * Remover um guardião
     */
    public function destroy(Request $request, string $id, DeleteGuardianAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $guardian = Guardian::query()->find($id);

        if ($guardian === null || ! Gate::forUser($user)->allows('delete', $guardian)) {
            return response()->json(['detail' => 'Guardião não encontrado ou acesso não autorizado.'], 404);
        }

        $action->execute($guardian);

        return response()->json([
            'status' => 'success',
            'message' => 'Guardião expurgado definitivamente.',
            'id' => $id,
        ]);
    }

    /**
     * Enviar foto do guardião
     *
     * Envio `multipart/form-data` no campo `photo`.
     *
     * @requestMediaType multipart/form-data
     */
    #[BodyParameter('photo', 'Foto do guardião.', required: true, type: 'string', format: 'binary')]
    public function uploadPhoto(Request $request, string $id, UploadGuardianPhotoAction $action): JsonResponse
    {
        $file = $request->file('photo');
        if ($file === null || is_array($file)) {
            return response()->json(['detail' => 'Envie a foto no campo "photo".'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $guardian = Guardian::query()->find($id);

        if ($guardian === null || ! Gate::forUser($user)->allows('update', $guardian)) {
            return response()->json(['detail' => 'Guardião não encontrado ou acesso não autorizado.'], 404);
        }

        return (new GuardianResource($action->execute($guardian, $file)))->response();
    }

    /**
     * Remover foto do guardião
     */
    public function deletePhoto(Request $request, string $id, DeleteGuardianPhotoAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $guardian = Guardian::query()->find($id);

        if ($guardian === null || ! Gate::forUser($user)->allows('update', $guardian)) {
            return response()->json(['detail' => 'Guardião não encontrado ou acesso não autorizado.'], 404);
        }

        return (new GuardianResource($action->execute($guardian)))->response();
    }
}
