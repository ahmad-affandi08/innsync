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

final readonly class MfaService
{
    public function __construct(
        private MfaStore $store,
        private OneTimePassword $oneTimePassword,
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
            throw new MfaEnrollmentMissing('A pending two-factor enrollment is required.');
        }

        if (! $this->oneTimePassword->verify($profile->secret, $code)) {
            throw new MfaVerificationFailed('The verification code is invalid.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->store->confirmEnrollment(
            $userId,
            array_map($this->hashRecoveryCode(...), $recoveryCodes),
        );

        return $recoveryCodes;
    }

    public function verifyChallenge(string $userId, string $code): void
    {
        $profile = $this->store->profile($userId);

        if (! $profile->confirmed || $profile->secret === null) {
            throw new MfaVerificationFailed('Two-factor authentication is not configured.');
        }

        if ($this->oneTimePassword->verify($profile->secret, $code)) {
            return;
        }

        if ($this->store->consumeRecoveryCode($userId, $this->hashRecoveryCode($code))) {
            return;
        }

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
}
