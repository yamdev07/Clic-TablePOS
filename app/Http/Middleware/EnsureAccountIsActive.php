<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque les tokens déjà émis d'un utilisateur désactivé
 * ou d'un restaurant suspendu.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $reason = $request->user()?->accessDenialReason();

        if ($reason) {
            return response()->json(['message' => $reason, 'code' => 'account_inactive'], 403);
        }

        return $next($request);
    }
}
