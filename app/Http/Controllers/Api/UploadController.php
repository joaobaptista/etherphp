<?php

namespace App\Http\Controllers\Api;

use App\Actions\Uploads\StoreUploadAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Perfil', weight: 30)]
class UploadController extends Controller
{
    /**
     * Enviar arquivo simples
     *
     * Upload genérico em `multipart/form-data`, no campo `file` (ou `photo`, `media`). Para mídia das cápsulas, use `POST /vault/media/upload`.
     *
     * @requestMediaType multipart/form-data
     */
    #[BodyParameter('file', 'Arquivo enviado.', required: true, type: 'string', format: 'binary')]
    public function store(Request $request, StoreUploadAction $action): JsonResponse
    {
        $file = $request->file('file') ?? $request->file('photo') ?? $request->file('media');
        if ($file === null || is_array($file)) {
            return response()->json(['detail' => 'file, photo or media is required'], 422);
        }

        /** @var User $user */
        $user = $request->user();

        return response()->json($action->execute($user, $file));
    }
}
