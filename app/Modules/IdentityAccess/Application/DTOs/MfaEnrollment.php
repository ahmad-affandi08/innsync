<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\DTOs;

final readonly class MfaEnrollment
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
    ) {}
}
