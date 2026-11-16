<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['message' => 'Accès réservé à l\'administration de la plateforme.'], 403);
        }

        return $next($request);
    }
}
