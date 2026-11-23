<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Icloud\ReceiveIcloudPhotoAction;
use App\Contracts\JwtServiceContract;
use App\Enums\MemorySourceEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Memory;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('App iOS', 'Rotas do app nativo do iPhone (POC das fotos do iCloud). No app, o servidor é só o endereço do site (local ou produção); ele completa `/api/ios` sozinho.', weight: 95)]
class IosController extends Controller
{
    /**
     * Entrar no app iOS
     *
     * Mesmo login de e-mail e senha do site. O token dura mais (`IOS_TOKEN_TTL_SECONDS`, 30 dias por padrão), para os envios em segundo plano não pararem.
     */
    public function login(LoginRequest $request, LoginUserAction $action, JwtServiceContract $jwt): JsonResponse
    {
        $user = $action->execute((string) $request->validated('email'), (string) $request->validated('password'))['user'];
        $ttl = (int) config('ethernity.ios.token_ttl_seconds');

        return response()->json([
            'access_token' => $jwt->issueForUser($user, $ttl),
            'token_type' => 'bearer',
            'expires_in' => $ttl,
            'user' => $this->presentUser($user),
        ]);
    }

    /**
     * Testar a conexão do app iOS
     *
     * Confere o token e diz quantas fotos do iPhone ainda esperam uma cápsula.
     */
    public function health(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'user' => $this->presentUser($user),
            'photosWaiting' => Memory::query()
                ->where('author_id', $user->id)
                ->where('source', MemorySourceEnum::Icloud)
                ->where(fn ($query) => $query->whereNull('capsule_id')->orWhere('capsule_id', 'capsule-default'))
                ->count(),
        ]);
    }

    /**
     * Enviar uma foto do iPhone
     *
     * O corpo é o próprio JPEG (`Content-Type: image/jpeg`), e o nome do caminho é o SHA-256 dele. Responde 201 quando a foto é nova e 200 quando ela já tinha chegado (pode reenviar sem criar cópia). SHA-256 diferente do arquivo: 422. Não é JPEG: 415. A foto fica esperando uma cápsula no site, como as do Google Fotos.
     *
     * @requestMediaType image/jpeg
     */
    #[HeaderParameter('X-Ethernity-Source', 'Como a foto foi escolhida no app: `picker` ou `library`.', type: 'string')]
    #[HeaderParameter('X-Ethernity-Asset-Local-ID', 'ID da foto na fototeca deste iPhone.', type: 'string')]
    #[HeaderParameter('X-Ethernity-Asset-Cloud-ID', 'ID da foto no iCloud, igual em todos os aparelhos da conta. Evita duplicatas.', type: 'string')]
    #[HeaderParameter('X-Ethernity-ICloud-Only', 'Se a foto estava só no iCloud: `true`, `false` ou `unknown`.', type: 'string')]
    public function storePhoto(Request $request, string $sha256, ReceiveIcloudPhotoAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $action->execute($user, $sha256, (string) $request->getContent(), [
            'source' => $request->header('X-Ethernity-Source'),
            'assetLocalId' => $request->header('X-Ethernity-Asset-Local-ID'),
            'assetCloudId' => $request->header('X-Ethernity-Asset-Cloud-ID'),
            'icloudOnly' => $request->header('X-Ethernity-ICloud-Only'),
        ]);
        $memory = $result['memory'];

        return response()->json([
            'id' => $memory->id,
            'duplicate' => ! $result['created'],
            // Full address (same host and scheme the app called, local or production) so the app can open it as is
            'mediaUrl' => $memory->media_url !== null ? url($memory->media_url) : null,
        ], $result['created'] ? 201 : 200);
    }

    /**
     * @return array{id: string, name: ?string, email: string}
     */
    private function presentUser(User $user): array
    {
        return ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
