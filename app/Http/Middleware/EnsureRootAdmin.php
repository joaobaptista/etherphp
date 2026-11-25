<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RootAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the root admins (ethernity.security.root_admin_emails) pass.
 * Granting or removing the admin role goes through here: a regular admin can never do it.
 */
class EnsureRootAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! RootAdmin::isRootEmail($user->email)) {
            return response()->json(['detail' => 'Somente o administrador principal (root) pode fazer isso.'], 403);
        }

        return $next($request);
    }
}
