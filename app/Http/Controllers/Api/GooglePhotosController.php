<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\HandleGoogleAuthCallbackAction;
use App\Contracts\GooglePhotosPickerContract;
use App\Contracts\JwtServiceContract;
use App\Http\Controllers\Controller;
use App\Models\Capsule;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

#[Group('Google Fotos', 'Conexão com o Google Fotos e importação pelo Google Photos Picker. Só fotos: vídeos são ignorados.', weight: 90)]
class GooglePhotosController extends Controller
{
    /**
     * Retorno do OAuth do Google
     *
     * Rota pública chamada pelo Google, para o login com Gmail e para a conexão com o Google Fotos. Redireciona de volta para o app.
     */
    public function callback(
        Request $request,
        JwtServiceContract $jwt,
        HandleGoogleAuthCallbackAction $handleLogin,
        GooglePhotosPickerContract $picker,
    ): RedirectResponse {
        $frontend = (string) config('ethernity.frontend_url', config('app.url'));
        $error = $request->query('error_description') ?? $request->query('error');

        if (is_string($error) && $error !== '') {
            return redirect()->away($frontend.'/?error='.rawurlencode($error));
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || $state === '') {
            return redirect()->away($frontend.'/?error='.rawurlencode('Parâmetros de autorização ausentes na resposta do Google.'));
        }

        $payload = $jwt->verifyState($state);
        if ($payload === null) {
            return redirect()->away($frontend.'/?error='.rawurlencode('O estado da autorização expirou ou é inválido.'));
        }

        // 1. OpenID Connect Gmail Login / Sign-up
        if (($payload['action'] ?? null) === 'auth' || ($payload['aud'] ?? null) === 'google-auth-state') {
            $result = $handleLogin->execute($code, $payload);

            return redirect()->away($result['redirectUrl']);
        }

        // 2. Google Photos Picker OAuth Connection
        $origin = $this->resolveAllowedOrigin((string) ($payload['origin'] ?? ''));
        try {
            $picker->handleOAuthCallback($code, $state);

            return redirect()->away($origin.'/?google_photos_status=connected');
        } catch (Throwable $e) {
            return redirect()->away($origin.'/?google_photos_status=error&message='.rawurlencode($e->getMessage()));
        }
    }

    /**
     * Situação da conexão com o Google Fotos
     */
    public function status(Request $request, GooglePhotosPickerContract $picker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $status = $picker->getConnectionStatus($user->id);

        return response()->json(array_merge($status, ['integration' => $status]));
    }

    /**
     * URL para conectar o Google Fotos
     *
     * O usuário autoriza no Google e volta pelo callback.
     */
    public function authorizationUrl(Request $request, GooglePhotosPickerContract $picker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $origin = (string) ($request->headers->get('Origin') ?: $request->getSchemeAndHttpHost());

        return response()->json($picker->buildAuthorizationUrl($user->id, $origin));
    }

    /**
     * Abrir o seletor do Google Fotos
     *
     * Cria uma sessão do Google Photos Picker e devolve o `pickerUri` para o usuário escolher as fotos. Responde 201.
     */
    #[BodyParameter('capsuleId', 'Cápsula de destino. Sem ela, as fotos ficam esperando destino.', type: 'string', default: 'capsule-default')]
    public function createSession(Request $request, GooglePhotosPickerContract $picker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $routeCapsuleId = $request->route('id');
        $capsuleId = is_string($routeCapsuleId) && $routeCapsuleId !== ''
            ? $routeCapsuleId
            : (string) $request->input('capsuleId', 'capsule-default');
        $this->assertOwnCapsuleIfExists($user, $capsuleId);

        return $this->pickerCall(function () use ($picker, $user, $capsuleId): JsonResponse {
            $session = $picker->createSession($user->id, $capsuleId);

            return response()->json(['session' => $session, 'data' => ['session' => $session]], 201);
        });
    }

    /**
     * Acompanhar a sessão do seletor
     *
     * Mostra se o usuário já escolheu as fotos e o progresso da importação (`importedCount`, `skippedCount`, `skippedVideos`, `alreadyInApp`).
     */
    public function pollSession(Request $request, GooglePhotosPickerContract $picker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $routeCapsuleId = $request->route('id');
        $capsuleId = is_string($routeCapsuleId) && $routeCapsuleId !== '' ? $routeCapsuleId : 'capsule-default';
        $sessionId = (string) $request->route('sessionId', '');
        $this->assertOwnCapsuleIfExists($user, $capsuleId);

        return $this->pickerCall(function () use ($picker, $user, $capsuleId, $sessionId): JsonResponse {
            $session = $picker->pollSession($user->id, $capsuleId, $sessionId);

            return response()->json(['session' => $session, 'data' => ['session' => $session]]);
        });
    }

    /**
     * Importar as fotos escolhidas
     *
     * A importação roda na fila, em segundo plano. Acompanhe pelo `GET` da sessão. Fotos repetidas e vídeos são ignorados.
     */
    #[BodyParameter('capsuleId', 'Cápsula de destino.', type: 'string', default: 'capsule-default')]
    public function importSession(Request $request, GooglePhotosPickerContract $picker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $routeCapsuleId = $request->route('id');
        $capsuleId = is_string($routeCapsuleId) && $routeCapsuleId !== ''
            ? $routeCapsuleId
            : (string) $request->input('capsuleId', 'capsule-default');
        $sessionId = (string) $request->route('sessionId', '');
        $this->assertOwnCapsuleIfExists($user, $capsuleId);

        return $this->pickerCall(fn (): JsonResponse => response()->json($picker->importSession($user->id, $capsuleId, $sessionId)));
    }

    /**
     * Desconectar o Google Fotos
     *
     * Revoga o acesso no Google e apaga os tokens guardados.
     */
    public function disconnect(Request $request, GooglePhotosPickerContract $picker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $picker->disconnect($user->id);

        return response()->json(['success' => true, 'message' => 'Google Fotos desconectado com sucesso.']);
    }

    /**
     * Picker errors are user-actionable (not connected, access revoked, Google rejected the
     * request): answer with the message instead of a generic 500 (APP_DEBUG=false hides it).
     *
     * @param  callable(): JsonResponse  $call
     */
    private function pickerCall(callable $call): JsonResponse
    {
        try {
            return $call();
        } catch (ConnectionException) {
            return response()->json(['detail' => 'Não foi possível falar com o Google Fotos agora. Tente novamente em instantes.'], 502);
        } catch (RuntimeException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        }
    }

    private function assertOwnCapsuleIfExists(User $user, string $capsuleId): void
    {
        if ($capsuleId === 'capsule-default' || $capsuleId === '') {
            return;
        }

        $capsule = Capsule::query()->find($capsuleId);
        if ($capsule !== null && $capsule->user_id !== $user->id) {
            abort(403, 'Esta cápsula pertence a outro usuário.');
        }
    }

    private function resolveAllowedOrigin(string $candidate): string
    {
        $clean = rtrim(trim($candidate), '/');
        /** @var list<string> $allowed */
        $allowed = config('ethernity.allowed_origins', []);

        if ($clean !== '' && in_array($clean, $allowed, true)) {
            return $clean;
        }

        return (string) config('ethernity.frontend_url', config('app.url'));
    }
}
