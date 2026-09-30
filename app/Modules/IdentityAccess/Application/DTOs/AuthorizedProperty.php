<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\DTOs;

final readonly class AuthorizedProperty
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
