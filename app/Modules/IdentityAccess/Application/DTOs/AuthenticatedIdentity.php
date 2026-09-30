<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\DTOs;

final readonly class AuthenticatedIdentity
{
    public function __construct(
        public string $userId,
        public bool $mfaRequired,
        public bool $mfaConfirmed,
    ) {}
}
