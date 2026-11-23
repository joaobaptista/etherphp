<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RootAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['detail' => 'Not authenticated'], 403);
        }

        if (! $user->is_admin && ! RootAdmin::isRootEmail($user->email)) {
            return response()->json(['detail' => 'Acesso restrito a administradores.'], 403);
        }

        return $next($request);
    }
}
