<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Modules\IdentityAccess\Application\DTOs\UserSession;

interface UserSessionRepository
{
    /** @return list<UserSession> */
    public function forUser(string $userId): array;

    public function revoke(string $userId, string $sessionId): bool;

    public function revokeAllExcept(string $userId, string $currentSessionId): int;
}
