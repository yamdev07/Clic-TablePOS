<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les routes métier (tables, menu, commandes…) exigent un utilisateur
 * rattaché à un restaurant. Les comptes plateforme n'y ont pas accès.
 */
class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->restaurant_id) {
            return response()->json(['message' => 'Aucun restaurant associé à ce compte.'], 403);
        }

        return $next($request);
    }
}
