<?php

namespace App\Http\Controllers\Api;

use App\Actions\Profile\UpdateProfileAction;
use App\Actions\Profile\UploadProfileAvatarAction;
use App\Data\UpdateProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Perfil', 'Dados e foto do usuário logado.', weight: 30)]
class ProfileController extends Controller
{
    /**
     * Ver perfil
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new UserResource($user))->response();
    }

    /**
     * Atualizar perfil
     */
    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $updated = $action->execute($user, new UpdateProfileData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            email: isset($validated['email']) ? (string) $validated['email'] : null,
            phone: isset($validated['phone']) ? (string) $validated['phone'] : null,
            password: isset($validated['password']) ? (string) $validated['password'] : null,
            avatarUrl: isset($validated['photo_url'])
                ? (string) $validated['photo_url']
                : (isset($validated['avatar_url']) ? (string) $validated['avatar_url'] : null),
        ));

        return (new UserResource($updated))->response();
    }

    /**
     * Enviar foto de perfil
     *
     * Envio `multipart/form-data` no campo `photo` (ou `file`).
     *
     * @requestMediaType multipart/form-data
     */
    #[BodyParameter('photo', 'Imagem do avatar.', required: true, type: 'string', format: 'binary')]
    public function uploadPhoto(Request $request, UploadProfileAvatarAction $action): JsonResponse
    {
        $file = $request->file('photo') ?? $request->file('file');
        if ($file === null || is_array($file)) {
            return response()->json(['detail' => 'Nenhuma foto enviada no campo "photo" ou "file".'], 400);
        }

        /** @var User $user */
        $user = $request->user();
        $result = $action->execute($user, $file);

        return response()->json([
            'success' => true,
            'photo_url' => $result['url'],
            'avatar_url' => $result['url'],
            'url' => $result['url'],
            'filename' => $result['filename'],
            'user' => new UserResource($result['user']),
        ]);
    }
}
