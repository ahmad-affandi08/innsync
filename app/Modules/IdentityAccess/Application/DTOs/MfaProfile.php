<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\DTOs;

final readonly class MfaProfile
{
    /**
     * @param  list<string>  $recoveryCodeHashes
     */
    public function __construct(
        public bool $required,
        public ?string $secret,
        public bool $confirmed,
        public array $recoveryCodeHashes,
    ) {}
}
