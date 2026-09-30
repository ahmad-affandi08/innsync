<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

interface UserPasswordUpdater
{
    public function update(string $userId, string $currentPassword, string $newPassword): bool;
}
