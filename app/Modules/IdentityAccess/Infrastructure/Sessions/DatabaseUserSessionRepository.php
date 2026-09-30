<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Sessions;

use App\Modules\IdentityAccess\Application\DTOs\UserSession;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use Illuminate\Support\Facades\DB;

final class DatabaseUserSessionRepository implements UserSessionRepository
{
    public function forUser(string $userId): array
    {
        return DB::table((string) config('session.table'))
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): UserSession => new UserSession(
                (string) $session->id,
                $this->deviceName((string) $session->user_agent),
                $session->ip_address === null ? null : (string) $session->ip_address,
                (int) $session->last_activity,
            ))
            ->all();
    }

    public function revoke(string $userId, string $sessionId): bool
    {
        return DB::table((string) config('session.table'))
            ->where('user_id', $userId)
            ->where('id', $sessionId)
            ->delete() === 1;
    }

    public function revokeAllExcept(string $userId, string $currentSessionId): int
    {
        return DB::table((string) config('session.table'))
            ->where('user_id', $userId)
            ->where('id', '<>', $currentSessionId)
            ->delete();
    }

    private function deviceName(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown device';
        }

        $device = preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent) === 1
            ? 'Mobile browser'
            : 'Desktop browser';

        return $device;
    }
}
