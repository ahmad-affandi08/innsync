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
    public function __construct(public array $kinds, public array $prefixes, public array $sources, public ?self $and = null) {}

    /**
     * What a person sees of the revenue through departments and outlets: what each department owns (the rooms for the front office, the laundry for the laundry, every
     * outlet's sales for F&B, the rest for general) and the sales of each F&B outlet, whose posting source is `pos_` and the outlet's code in lower case.
     *
     * @param  list<string>  $departments
     * @param  list<string>  $outletCodes
     */
    public static function of(array $departments, array $outletCodes): self
    {
        $kinds = [];
        $prefixes = [];

        foreach ($departments as $department) {
            match ($department) {
                'front_office' => $kinds[] = 'room',
                'laundry' => $kinds[] = 'laundry',
                'fnb' => $prefixes[] = 'pos_',
                'general' => $kinds = [...$kinds, 'other', 'outlet'],
                default => null,
            };
        }

        return new self($kinds, $prefixes, array_map(static fn (string $code): string => 'pos_'.strtolower($code), $outletCodes));
    }

    /** What both this and the other allow: the filters of a report narrow one another. */
    public function narrowedBy(self $other): self
    {
        return new self($this->kinds, $this->prefixes, $this->sources, $other);
    }

    public function allows(string $kind, string $source): bool
    {
        return $this->allowsOwn($kind, $source) && ($this->and === null || $this->and->allows($kind, $source));
    }

    private function allowsOwn(string $kind, string $source): bool
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
