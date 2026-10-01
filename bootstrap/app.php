<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\EnsureActiveUser;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\EnsureMfaVerified;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\RequirePermission;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\ResolvePropertyContext;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Infrastructure\Observability\AssignCorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);

        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'mfa' => EnsureMfaVerified::class,
            'permission' => RequirePermission::class,
            'property' => ResolvePropertyContext::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function ($response) {
            $response->headers->set(
                'X-Correlation-ID',
                app(CorrelationId::class)->current(),
            );

            return $response;
        });
    })->create();
