<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\DTOs;

final readonly class UserSession
{
    public function __construct(
        public string $id,
        public string $device,
        public ?string $ipAddress,
        public int $lastActivity,
    ) {}
}
