<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Modules\IdentityAccess\Application\DTOs\MfaProfile;

interface MfaStore
{
    public function profile(string $userId): MfaProfile;

    public function beginEnrollment(string $userId, string $secret): void;

    /** @param list<string> $recoveryCodeHashes */
    public function confirmEnrollment(string $userId, array $recoveryCodeHashes): void;

    public function consumeRecoveryCode(string $userId, string $recoveryCodeHash): bool;
}
