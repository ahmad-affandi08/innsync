<?php

declare(strict_types=1);

namespace App\Shared\Domain\Tenancy;

use InvalidArgumentException;

final readonly class PropertyId
{
    private const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::ULID_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Property ID must be a valid ULID.');
        }

        return new self(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
