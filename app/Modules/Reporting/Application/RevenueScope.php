<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

/**
 * Which part of the revenue a person with a limited grant sees (FR-DSH-022): what is posted under a kind of source (`room`, `laundry`, an `outlet` the owner named, or
 * the `other` rest), under a prefix of the source (`pos_` for every F&B outlet) or under one source (`pos_rest`).
 */
final readonly class RevenueScope
{
    /**
     * @param  list<string>  $kinds
     * @param  list<string>  $prefixes
     * @param  list<string>  $sources
     */
    public function __construct(public array $kinds, public array $prefixes, public array $sources) {}

    public function allows(string $kind, string $source): bool
    {
        if (in_array($kind, $this->kinds, true) || in_array($source, $this->sources, true)) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($source, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
