<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Catalog;

use InvalidArgumentException;

/** The number shown on the door, such as 101 or 12A. Uppercase, at most 20 characters. */
final readonly class RoomNumber
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/D', $normalized) !== 1) {
            throw new InvalidArgumentException('A room number is 1 to 20 letters, digits or hyphens.');
        }

        return new self($normalized);
    }
}
