<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authentication;

use App\Modules\IdentityAccess\Application\DTOs\AuthenticatedIdentity;
use App\Modules\IdentityAccess\Application\Ports\CredentialAuthenticator;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class EloquentCredentialAuthenticator implements CredentialAuthenticator
{
    public function __construct(
        private UserAccessReader $accessReader,
        private SecurityLog $securityLog,
    ) {}

    public function authenticate(string $email, string $password): ?AuthenticatedIdentity
    {
        return DB::transaction(function () use ($email, $password): ?AuthenticatedIdentity {
            $user = UserRecord::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                Hash::check($password, Hash::make(bin2hex(random_bytes(16))));
                $this->recordAuthentication(null, SecurityEventOutcome::Failure, 'invalid_credentials');

                return null;
            }

            if (! $user->is_active || $user->locked_until?->isFuture()) {
                $this->recordAuthentication(
                    (string) $user->getKey(),
                    SecurityEventOutcome::Denied,
                    'account_unavailable',
                );

                return null;
            }

            if (! Hash::check($password, $user->getAuthPassword())) {
                $attempts = (int) $user->failed_login_attempts + 1;
                $user->failed_login_attempts = $attempts;

                if ($attempts >= (int) config('identity_access.max_failed_login_attempts')) {
                    $user->locked_until = now()->addSeconds((int) config('identity_access.lockout_seconds'));
                }

                $user->save();
                $this->recordAuthentication(
                    (string) $user->getKey(),
                    SecurityEventOutcome::Failure,
                    $user->locked_until?->isFuture() === true ? 'account_locked' : 'invalid_credentials',
                );

                return null;
            }

            if (Hash::needsRehash($user->getAuthPassword())) {
                $user->password = $password;
            }

            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $user->last_login_at = now();
            $user->save();
            $this->recordAuthentication(
                (string) $user->getKey(),
                SecurityEventOutcome::Success,
            );

            return new AuthenticatedIdentity(
                (string) $user->getKey(),
                $this->accessReader->requiresMfa((string) $user->getKey()),
                $user->two_factor_confirmed_at !== null,
            );
        }, 3);
    }

    private function recordAuthentication(
        ?string $actorId,
        SecurityEventOutcome $outcome,
        ?string $reasonCode = null,
    ): void {
        $this->securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::Authentication->value,
            $outcome,
            $actorId,
            metadata: $reasonCode === null ? [] : ['reason_code' => $reasonCode],
        ));
    }
}
