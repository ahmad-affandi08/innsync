<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authentication;

use App\Modules\IdentityAccess\Application\Ports\UserPasswordUpdater;
use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class EloquentUserPasswordUpdater implements UserPasswordUpdater
{
    public function __construct(private SecurityLog $securityLog) {}

    public function update(string $userId, string $currentPassword, string $newPassword): bool
    {
        return DB::transaction(function () use ($userId, $currentPassword, $newPassword): bool {
            $user = UserRecord::query()->lockForUpdate()->findOrFail($userId);

            if (! Hash::check($currentPassword, $user->getAuthPassword())) {
                $this->securityLog->record(new SecurityEvent(
                    IdentityAccessSecurityEvent::PasswordChange->value,
                    SecurityEventOutcome::Failure,
                    $userId,
                    metadata: ['reason_code' => 'current_password_mismatch'],
                ));

                return false;
            }

            $user->password = $newPassword;
            $user->password_changed_at = now();
            $user->save();
            $this->securityLog->record(new SecurityEvent(
                IdentityAccessSecurityEvent::PasswordChange->value,
                SecurityEventOutcome::Success,
                $userId,
            ));

            return true;
        }, 3);
    }
}
