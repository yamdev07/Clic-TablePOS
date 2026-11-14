<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorisation des canaux privés temps réel : POST /api/broadcasting/auth (token Sanctum)
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [
            \App\Http\Middleware\GzipResponse::class,
        ]);

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            'tenant' => \App\Http\Middleware\EnsureTenantUser::class,
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
