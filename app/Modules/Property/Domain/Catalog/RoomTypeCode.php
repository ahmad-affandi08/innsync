<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Catalog;

use InvalidArgumentException;

/** Short stable code of a room type, such as DLX or SUITE-1. Uppercase; never reused for another type. */
final readonly class RoomTypeCode
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));

        if (preg_match('/^[A-Z][A-Z0-9-]{1,19}$/D', $normalized) !== 1) {
            throw new InvalidArgumentException('A room type code is 2 to 20 letters, digits or hyphens, starting with a letter.');
        }

        return new self($normalized);
    }
}
