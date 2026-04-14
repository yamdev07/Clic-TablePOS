<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compresse les réponses API en gzip si le client l'accepte.
 * Réduit typiquement les payloads JSON de 70-80 %.
 */
class GzipResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (
            ! $request->headers->contains('Accept-Encoding', 'gzip') ||
            $response->headers->has('Content-Encoding') ||
            strlen($response->getContent()) < 1024 // ne pas compresser < 1 Ko
        ) {
            return $response;
        }

        $compressed = gzencode($response->getContent(), 6);

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', strlen($compressed));
        $response->headers->remove('Transfer-Encoding');

        return $response;
    }
}
