<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Authorization;

use InvalidArgumentException;

final readonly class PermissionCode
{
    private const PATTERN = '/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (strlen($value) > 120 || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Permission code must use feature.action segments.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
