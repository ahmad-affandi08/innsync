<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\IdentityAccess\Application\Ports\CredentialAuthenticator;
use App\Modules\IdentityAccess\Application\Ports\MfaStore;
use App\Modules\IdentityAccess\Application\Ports\OneTimePassword;
use App\Modules\IdentityAccess\Application\Ports\PermissionGrantReader;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Modules\IdentityAccess\Application\Ports\UserPasswordUpdater;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use App\Modules\IdentityAccess\Infrastructure\Authentication\EloquentCredentialAuthenticator;
use App\Modules\IdentityAccess\Infrastructure\Authentication\EloquentUserPasswordUpdater;
use App\Modules\IdentityAccess\Infrastructure\Authorization\EloquentPermissionGrantReader;
use App\Modules\IdentityAccess\Infrastructure\Authorization\EloquentUserAccessReader;
use App\Modules\IdentityAccess\Infrastructure\Mfa\EloquentMfaStore;
use App\Modules\IdentityAccess\Infrastructure\Mfa\TotpOneTimePassword;
use App\Modules\IdentityAccess\Infrastructure\Sessions\DatabaseUserSessionRepository;
use App\Shared\Application\Audit\AuditWriter;
use App\Shared\Application\Idempotency\IdempotencyContext;
use App\Shared\Application\Idempotency\IdempotencyStore;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Security\SecurityEventWriter;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Infrastructure\Audit\DatabaseAuditWriter;
use App\Shared\Infrastructure\Idempotency\DatabaseIdempotencyStore;
use App\Shared\Infrastructure\Observability\LaravelCorrelationId;
use App\Shared\Infrastructure\Security\DatabaseSecurityEventWriter;
use App\Shared\Infrastructure\Transactions\MySqlTransactionRunner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PropertyContext::class, static fn (): PropertyContext => new PropertyContext);
        $this->app->scoped(CorrelationId::class, LaravelCorrelationId::class);
        $this->app->scoped(IdempotencyContext::class, static fn (): IdempotencyContext => new IdempotencyContext);
        $this->app->bind(AuditWriter::class, DatabaseAuditWriter::class);
        $this->app->bind(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->bind(SecurityEventWriter::class, DatabaseSecurityEventWriter::class);
        $this->app->bind(TransactionRunner::class, MySqlTransactionRunner::class);
        $this->app->bind(CredentialAuthenticator::class, EloquentCredentialAuthenticator::class);
        $this->app->bind(UserAccessReader::class, EloquentUserAccessReader::class);
        $this->app->bind(UserPasswordUpdater::class, EloquentUserPasswordUpdater::class);
        $this->app->bind(PermissionGrantReader::class, EloquentPermissionGrantReader::class);
        $this->app->bind(OneTimePassword::class, TotpOneTimePassword::class);
        $this->app->bind(MfaStore::class, EloquentMfaStore::class);
        $this->app->bind(UserSessionRepository::class, DatabaseUserSessionRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(static fn (): Password => Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('identity_access.login_rate_limit_per_minute'),
        )->by(hash(
            'sha256',
            strtolower((string) $request->input('email')).'|'.$request->ip(),
        )));

        RateLimiter::for('mfa', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash(
                'sha256',
                (string) $request->user()?->getAuthIdentifier().'|'.$request->ip(),
            )));

        RateLimiter::for('sensitive', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash(
                'sha256',
                (string) $request->user()?->getAuthIdentifier().'|'.$request->ip(),
            )));
    }
}
