<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Modules\IdentityAccess\Application\Ports\UserPasswordUpdater;

final readonly class ChangePassword
{
    public function __construct(private UserPasswordUpdater $passwordUpdater) {}

    public function handle(string $userId, string $currentPassword, string $newPassword): bool
    {
        return $this->passwordUpdater->update($userId, $currentPassword, $newPassword);
    }
}
