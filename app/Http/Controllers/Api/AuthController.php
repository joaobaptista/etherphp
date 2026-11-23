<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Contracts\GoogleOAuthServiceContract;
use App\Data\RegisterUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Autenticação', 'Cadastro, login e sessão. Devolve o JWT usado nas outras rotas.', weight: 20)]
class AuthController extends Controller
{
    /**
     * Criar conta
     *
     * Devolve o JWT e o usuário criado.
     */
    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->execute(RegisterUserData::from($request->validated()));

        return (new UserResource($user))->response()->setStatusCode(200);
    }

    /**
     * Entrar com e-mail e senha
     *
     * Devolve o JWT (`access_token`) e o usuário.
     */
    public function login(LoginRequest $request, LoginUserAction $action): JsonResponse
    {
        $result = $action->execute(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
        );

        return response()->json([
            'access_token' => $result['access_token'],
            'token_type' => $result['token_type'],
            'user' => new UserResource($result['user']),
        ]);
    }

    /**
     * Usuário logado
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['user' => new UserResource($user)]);
    }

    /**
     * URL de login com Google
     *
     * O navegador é levado a essa URL. A volta passa por `GET /integrations/google-photos/callback`, que redireciona para o app com o JWT.
     */
    public function googleUrl(Request $request, GoogleOAuthServiceContract $googleOAuth): JsonResponse
    {
        if (! $googleOAuth->isConfigured()) {
            return response()->json(['detail' => 'Google OAuth não está configurado no servidor.'], 500);
        }

        $origin = (string) ($request->headers->get('Origin') ?: $request->getSchemeAndHttpHost());

        return response()->json(['url' => $googleOAuth->buildLoginUrl($origin)]);
    }
}
