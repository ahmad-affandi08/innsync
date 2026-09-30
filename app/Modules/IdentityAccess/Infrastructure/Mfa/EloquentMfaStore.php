<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Mfa;

use App\Modules\IdentityAccess\Application\DTOs\MfaProfile;
use App\Modules\IdentityAccess\Application\Ports\MfaStore;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Illuminate\Support\Facades\DB;

final readonly class EloquentMfaStore implements MfaStore
{
    public function __construct(private UserAccessReader $accessReader) {}

    public function profile(string $userId): MfaProfile
    {
        $user = UserRecord::query()->findOrFail($userId);

        return new MfaProfile(
            $this->accessReader->requiresMfa($userId),
            $user->two_factor_secret,
            $user->two_factor_confirmed_at !== null,
            array_values($user->two_factor_recovery_codes ?? []),
        );
    }

    public function beginEnrollment(string $userId, string $secret): void
    {
        $user = UserRecord::query()->findOrFail($userId);
        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
    }

    public function confirmEnrollment(string $userId, array $recoveryCodeHashes): void
    {
        $user = UserRecord::query()->findOrFail($userId);
        $user->two_factor_recovery_codes = $recoveryCodeHashes;
        $user->two_factor_confirmed_at = now();
        $user->save();
    }

    public function consumeRecoveryCode(string $userId, string $recoveryCodeHash): bool
    {
        return DB::transaction(function () use ($userId, $recoveryCodeHash): bool {
            $user = UserRecord::query()->lockForUpdate()->findOrFail($userId);
            $hashes = array_values($user->two_factor_recovery_codes ?? []);
            $match = null;

            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $recoveryCodeHash)) {
                    $match = $index;

                    break;
                }
            }

            if ($match === null) {
                return false;
            }

            unset($hashes[$match]);
            $user->two_factor_recovery_codes = array_values($hashes);
            $user->save();

            return true;
        }, 3);
    }
}
