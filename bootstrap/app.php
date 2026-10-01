<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\EnsureActiveUser;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\EnsureMfaVerified;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\RequirePermission;
use App\Modules\IdentityAccess\Presentation\Http\Middleware\ResolvePropertyContext;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Infrastructure\Backup\BackupDecryptCommand;
use App\Shared\Infrastructure\Backup\BackupKeygenCommand;
use App\Shared\Infrastructure\Backup\BackupRunCommand;
use App\Shared\Infrastructure\Backup\BackupVerifyCommand;
use App\Shared\Infrastructure\Deployment\PreflightCommand;
use App\Shared\Infrastructure\Deployment\SmokeCommand;
use App\Shared\Infrastructure\Http\Errors\ErrorEnvelopeFactory;
use App\Shared\Infrastructure\Http\Errors\RenderErrorEnvelope;
use App\Shared\Infrastructure\Idempotency\RequireIdempotencyKey;
use App\Shared\Infrastructure\Localization\ResolveLocale;
use App\Shared\Infrastructure\Observability\AssignCorrelationId;
use App\Shared\Infrastructure\Observability\Health\ErrorRate;
use App\Shared\Infrastructure\Observability\Health\HealthAlertsCommand;
use App\Shared\Infrastructure\Observability\Health\HealthCheckCommand;
use App\Shared\Infrastructure\Observability\Health\HeartbeatCommand;
use App\Shared\Infrastructure\Outbox\DrainOutboxCommand;
use App\Shared\Infrastructure\Outbox\RetryDeadLetterCommand;
use App\Shared\Infrastructure\Retention\RetentionPurgeCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('throttle:health')->group(base_path('routes/health.php'));
            Route::middleware(['api', 'throttle:webhooks'])->prefix('api')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withCommands([
        DrainOutboxCommand::class,
        RetryDeadLetterCommand::class,
        HealthCheckCommand::class,
        HealthAlertsCommand::class,
        HeartbeatCommand::class,
        BackupRunCommand::class,
        BackupVerifyCommand::class,
        BackupKeygenCommand::class,
        BackupDecryptCommand::class,
        PreflightCommand::class,
        SmokeCommand::class,
        RetentionPurgeCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);

        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'idempotent' => RequireIdempotencyKey::class,
            'mfa' => EnsureMfaVerified::class,
            'permission' => RequirePermission::class,
            'property' => ResolvePropertyContext::class,
        ]);

        $middleware->web(append: [
            ResolveLocale::class,
            HandleInertiaRequests::class,
        ]);

        // Token/API clients have no session: they negotiate the language from Accept-Language only.
        $middleware->api(append: [
            ResolveLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReport(ErrorEnvelopeFactory::EXPECTED);

        // Runs for reported (unexpected) exceptions only; returns nothing so normal logging continues.
        $exceptions->reportable(function (Throwable $e): void {
            app(ErrorRate::class)->record();
        });

        $exceptions->render(
            fn (Throwable $e, Request $request) => app(RenderErrorEnvelope::class)($e, $request),
        );

        $exceptions->respond(function ($response) {
            $response->headers->set(
                'X-Correlation-ID',
                app(CorrelationId::class)->current(),
            );

            return $response;
        });
    })->create();
