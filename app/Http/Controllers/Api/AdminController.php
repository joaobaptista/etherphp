<?php

namespace App\Http\Controllers\Api;

use App\Actions\Admin\BatchDeleteUsersAction;
use App\Actions\Admin\DeleteUserAction;
use App\Actions\Admin\GetAdminMetricsAction;
use App\Actions\Admin\ListUsersAction;
use App\Actions\Admin\UpdateUserRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Administração', 'Exige um usuário administrador.', weight: 120)]
class AdminController extends Controller
{
    /**
     * Listar usuários
     */
    #[QueryParameter('search', 'Busca por nome ou e-mail.', type: 'string')]
    #[QueryParameter('role', 'Filtro por papel.', type: "'all'|'admin'|'user'", default: 'all')]
    public function users(Request $request, ListUsersAction $action): JsonResponse
    {
        $users = $action->execute(
            (string) $request->query('search', ''),
            (string) $request->query('role', 'all'),
        );

        return response()->json([
            'total' => $users->count(),
            'users' => UserResource::collection($users)->resolve(),
        ]);
    }

    /**
     * Conceder ou remover o papel de administrador
     */
    #[BodyParameter('is_admin', 'true para tornar administrador.', required: true, type: 'bool')]
    public function updateRole(Request $request, string $id, UpdateUserRoleAction $action): JsonResponse
    {
        if (! $request->has('is_admin')) {
            return response()->json(['detail' => 'Parâmetro is_admin (booleano ou 0/1) é obrigatório.'], 400);
        }

        $isAdmin = filter_var($request->input('is_admin'), FILTER_VALIDATE_BOOLEAN);
        $updated = $action->execute($id, $isAdmin);
        $label = $updated->name !== '' ? $updated->name : $updated->email;

        return response()->json([
            'success' => true,
            'id' => $updated->id,
            'name' => $updated->name,
            'email' => $updated->email,
            'is_admin' => $updated->is_admin ? 1 : 0,
            'message' => $updated->is_admin
                ? "Usuário {$label} agora é um Administrador!"
                : "Privilégios de Administrador removidos de {$label}.",
        ]);
    }

    /**
     * Métricas do painel
     */
    public function metrics(GetAdminMetricsAction $action): JsonResponse
    {
        return response()->json($action->execute());
    }

    /**
     * Excluir um usuário
     */
    public function destroyUser(Request $request, string $id, DeleteUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $deleted = $action->execute($actor, $id);
        $label = $deleted->name !== '' ? $deleted->name : $deleted->email;

        return response()->json([
            'success' => true,
            'id' => $deleted->id,
            'name' => $deleted->name,
            'email' => $deleted->email,
            'message' => "Usuário {$label} excluído permanentemente do sistema com sucesso.",
        ]);
    }

    /**
     * Excluir usuários em lote
     */
    #[BodyParameter('userIds', 'IDs dos usuários.', required: true, type: 'array<string>')]
    public function batchDeleteUsers(Request $request, BatchDeleteUsersAction $action): JsonResponse
    {
        $userIds = $request->input('userIds');
        if (! is_array($userIds) || $userIds === []) {
            return response()->json(['detail' => 'Lista de IDs de usuários inválida ou vazia.'], 400);
        }

        /** @var User $actor */
        $actor = $request->user();
        /** @var list<string> $cleanIds */
        $cleanIds = array_values(array_map(static fn (mixed $v): string => (string) $v, $userIds));
        $result = $action->execute($actor, $cleanIds);

        return response()->json(array_merge(['success' => true], $result));
    }
}
