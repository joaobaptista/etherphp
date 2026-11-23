<?php

namespace App\Actions\Auth;

use App\Contracts\GoogleOAuthServiceContract;
use App\Contracts\JwtServiceContract;
use App\Enums\PlanIdEnum;
use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Support\Str;
use Throwable;

class HandleGoogleAuthCallbackAction
{
    public function __construct(
        protected GoogleOAuthServiceContract $googleOAuth,
        protected JwtServiceContract $jwt,
    ) {}

    /**
     * @param  array<string, mixed>  $statePayload
     * @return array{redirectUrl: string}
     */
    public function execute(string $code, array $statePayload): array
    {
        $targetFrontend = $this->resolveTargetFrontend((string) ($statePayload['origin'] ?? ''));

        try {
            $tokenData = $this->googleOAuth->exchangeCode($code);
            $accessToken = (string) ($tokenData['access_token'] ?? '');
            $profile = $this->googleOAuth->fetchUserInfo($accessToken);

            $email = strtolower(trim((string) ($profile['email'] ?? '')));
            if ($email === '') {
                return ['redirectUrl' => $targetFrontend.'/?error='.rawurlencode('Sua conta Google não forneceu um endereço de e-mail válido.')];
            }

            // Require verified email to prevent account takeover
            if (($profile['email_verified'] ?? null) !== true) {
                return ['redirectUrl' => $targetFrontend.'/?error='.rawurlencode('Este e-mail ainda não foi verificado pelo Google. Verifique-o na sua conta Google e tente de novo.')];
            }

            $googleName = trim((string) ($profile['name'] ?? $profile['given_name'] ?? explode('@', $email)[0]));
            $googlePicture = isset($profile['picture']) ? (string) $profile['picture'] : null;
            $isRootAdmin = RootAdmin::isRootEmail($email);

            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                $keepsCustomAvatar = ! $this->googleOAuth->isGoogleManagedAvatar($user->avatar_url);
                $nextAvatar = $keepsCustomAvatar
                    ? $user->avatar_url
                    : ($googlePicture !== null ? $this->googleOAuth->cacheAvatar($googlePicture, $user->id) : $user->avatar_url);

                $user->update([
                    'name' => trim($user->name) !== '' ? $user->name : $googleName,
                    'avatar_url' => $nextAvatar,
                    'is_admin' => $isRootAdmin || $user->is_admin,
                ]);
            } else {
                $newId = (string) Str::uuid();
                $cachedAvatar = $googlePicture !== null
                    ? $this->googleOAuth->cacheAvatar($googlePicture, $newId)
                    : null;

                $user = User::query()->create([
                    'id' => $newId,
                    'name' => $googleName,
                    'email' => $email,
                    'password_hash' => null,
                    'avatar_url' => $cachedAvatar,
                    'plan_id' => PlanIdEnum::Infinity,
                    'is_admin' => $isRootAdmin,
                ]);
            }

            $token = $this->jwt->issueForUser($user);
            $serializedUser = json_encode([
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'phone' => $user->phone,
                'photo_url' => $user->avatar_url,
                'avatar_url' => $user->avatar_url,
                'avatar' => $user->avatar_url,
                'plan_id' => $user->plan_id->value,
                'plan' => $user->plan_id->label(),
                'is_admin' => ($isRootAdmin || $user->is_admin) ? 1 : 0,
            ], JSON_THROW_ON_ERROR);

            // Fragment (#token=): browser never sends it to servers/logs/Referer
            return [
                'redirectUrl' => $targetFrontend.'/#token='.rawurlencode($token).'&user='.rawurlencode($serializedUser),
            ];
        } catch (Throwable $e) {
            return [
                'redirectUrl' => $targetFrontend.'/?error='.rawurlencode($e->getMessage()),
            ];
        }
    }

    private function resolveTargetFrontend(string $stateOrigin): string
    {
        $clean = rtrim(trim($stateOrigin), '/');
        /** @var list<string> $allowed */
        $allowed = config('ethernity.allowed_origins', []);

        if ($clean !== '' && in_array($clean, $allowed, true)) {
            return $clean;
        }

        return (string) config('ethernity.frontend_url', config('app.url'));
    }
}
