<?php

namespace App\Http\Middleware;

use App\Contracts\JwtServiceContract;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    public function __construct(
        protected JwtServiceContract $jwt,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = ''): Response
    {
        $allowQueryToken = $mode === 'allow-query';
        $token = $this->extractToken($request, $allowQueryToken);

        if ($token === null || $token === '') {
            return response()->json(['detail' => 'Not authenticated'], 403);
        }

        $claims = $this->jwt->verify($token);
        if ($claims === null || (! isset($claims['uid']) && ! isset($claims['sub']))) {
            return response()->json(['detail' => 'Could not validate credentials'], 401);
        }

        $user = null;
        if (isset($claims['uid']) && is_string($claims['uid']) && trim($claims['uid']) !== '') {
            $user = User::query()->find(trim($claims['uid']));
        }

        if ($user === null && isset($claims['sub']) && is_string($claims['sub'])) {
            $email = strtolower(trim($claims['sub']));
            if ($email !== '') {
                $user = User::query()->where('email', $email)->first();
            }
        }

        if ($user === null) {
            return response()->json(['detail' => 'Could not validate credentials'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }

    private function extractToken(Request $request, bool $allowQueryToken): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && trim($bearer) !== '') {
            return trim($bearer);
        }

        if ($allowQueryToken) {
            $queryToken = $request->query('token');
            if (is_string($queryToken) && trim($queryToken) !== '') {
                return trim($queryToken);
            }
        }

        return null;
    }
}
