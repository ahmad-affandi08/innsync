<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Mfa;

use App\Modules\IdentityAccess\Application\DTOs\MfaEnrollment;
use App\Modules\IdentityAccess\Application\DTOs\MfaProfile;
use App\Modules\IdentityAccess\Application\Exceptions\MfaAlreadyEnabled;
use App\Modules\IdentityAccess\Application\Exceptions\MfaEnrollmentMissing;
use App\Modules\IdentityAccess\Application\Exceptions\MfaVerificationFailed;
use App\Modules\IdentityAccess\Application\Ports\MfaStore;
use App\Modules\IdentityAccess\Application\Ports\OneTimePassword;
use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;

final readonly class MfaService
{
    public function __construct(
        private MfaStore $store,
        private OneTimePassword $oneTimePassword,
        private SecurityLog $securityLog,
    ) {}

    public function profile(string $userId): MfaProfile
    {
        return $this->store->profile($userId);
    }

    public function beginEnrollment(string $userId, string $accountName): MfaEnrollment
    {
        $profile = $this->store->profile($userId);

        if ($profile->confirmed) {
            throw new MfaAlreadyEnabled('Two-factor authentication is already enabled.');
        }

        $secret = $this->oneTimePassword->generateSecret();
        $this->store->beginEnrollment($userId, $secret);
        $this->record(
            IdentityAccessSecurityEvent::MfaEnrollment,
            SecurityEventOutcome::Success,
            $userId,
            ['stage' => 'started'],
        );

        return new MfaEnrollment(
            $secret,
            $this->oneTimePassword->provisioningUri($secret, $accountName),
        );
    }

    public function pendingEnrollment(string $userId, string $accountName): ?MfaEnrollment
    {
        $profile = $this->store->profile($userId);

        if ($profile->confirmed || $profile->secret === null) {
            return null;
        }

        return new MfaEnrollment(
            $profile->secret,
            $this->oneTimePassword->provisioningUri($profile->secret, $accountName),
        );
    }

    /** @return list<string> */
    public function confirmEnrollment(string $userId, string $code): array
    {
        $profile = $this->store->profile($userId);

        if ($profile->secret === null || $profile->confirmed) {
            $this->record(
                IdentityAccessSecurityEvent::MfaEnrollment,
                SecurityEventOutcome::Failure,
                $userId,
                ['reason_code' => 'enrollment_missing'],
            );

            throw new MfaEnrollmentMissing('A pending two-factor enrollment is required.');
        }

        if (! $this->oneTimePassword->verify($profile->secret, $code)) {
            $this->record(
                IdentityAccessSecurityEvent::MfaEnrollment,
                SecurityEventOutcome::Failure,
                $userId,
                ['reason_code' => 'invalid_code'],
            );

            throw new MfaVerificationFailed('The verification code is invalid.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->store->confirmEnrollment(
            $userId,
            array_map($this->hashRecoveryCode(...), $recoveryCodes),
        );
        $this->record(
            IdentityAccessSecurityEvent::MfaEnrollment,
            SecurityEventOutcome::Success,
            $userId,
            ['stage' => 'confirmed'],
        );

        return $recoveryCodes;
    }

    public function verifyChallenge(string $userId, string $code): void
    {
        $profile = $this->store->profile($userId);

        if (! $profile->confirmed || $profile->secret === null) {
            $this->record(
                IdentityAccessSecurityEvent::MfaChallenge,
                SecurityEventOutcome::Failure,
                $userId,
                ['reason_code' => 'not_configured'],
            );

            throw new MfaVerificationFailed('Two-factor authentication is not configured.');
        }

        if ($this->oneTimePassword->verify($profile->secret, $code)) {
            $this->record(
                IdentityAccessSecurityEvent::MfaChallenge,
                SecurityEventOutcome::Success,
                $userId,
                ['method' => 'totp'],
            );

            return;
        }

        if ($this->store->consumeRecoveryCode($userId, $this->hashRecoveryCode($code))) {
            $this->record(
                IdentityAccessSecurityEvent::MfaChallenge,
                SecurityEventOutcome::Success,
                $userId,
                ['method' => 'recovery_code'],
            );

            return;
        }

        $this->record(
            IdentityAccessSecurityEvent::MfaChallenge,
            SecurityEventOutcome::Failure,
            $userId,
            ['reason_code' => 'invalid_code'],
        );

        throw new MfaVerificationFailed('The verification code is invalid.');
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => strtoupper(substr(bin2hex(random_bytes(5)), 0, 5).'-'.substr(bin2hex(random_bytes(5)), 0, 5)),
            range(1, 8),
        );
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        IdentityAccessSecurityEvent $event,
        SecurityEventOutcome $outcome,
        string $actorId,
        array $metadata,
    ): void {
        $this->securityLog->record(new SecurityEvent(
            $event->value,
            $outcome,
            $actorId,
            metadata: $metadata,
        ));
    }
}
