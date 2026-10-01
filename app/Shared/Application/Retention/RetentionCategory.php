<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use InvalidArgumentException;

/** One kind of data with its retention period and the bounds a property may configure within. */
final readonly class RetentionCategory
{
    public function __construct(
        public string $key,
        public int $defaultDays,
        public int $minimumDays,
        public ?int $maximumDays,
        public bool $statutory,
        public string $anchor,
        public bool $purgeable,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,59}$/', $key) !== 1) {
            throw new InvalidArgumentException('A retention category key is lowercase snake case.');
        }

        if ($minimumDays < 0 || $defaultDays < $minimumDays || ($maximumDays !== null && ($maximumDays < $minimumDays || $defaultDays > $maximumDays))) {
            throw new InvalidArgumentException("Retention bounds of {$key} are inconsistent.");
        }
    }

    public function allows(int $days): bool
    {
        return $days >= $this->minimumDays && ($this->maximumDays === null || $days <= $this->maximumDays);
    }
}
