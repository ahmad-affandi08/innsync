<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/**
 * How a property works. A profile is a starting point: it chooses which optional departments are in use and which roles are offered; the owner can
 * switch any department on or off afterwards. The core (front office, housekeeping, reports, property settings) is always on.
 */
final class PropertyProfiles
{
    public const HOTEL = 'hotel';

    public const SMALL_RESORT = 'small_resort';

    public const VILLA = 'villa';

    /** The departments a property may leave out. Front office, housekeeping, reports and the property's own settings are never among them. */
    public const OPTIONAL_MODULES = ['laundry', 'fnb', 'kitchen', 'maintenance', 'hr', 'inventory', 'finance'];

    /** @return array<string, list<string>> profile => the departments it does not use */
    public static function presets(): array
    {
        return [
            self::HOTEL => [],
            self::SMALL_RESORT => ['inventory', 'hr'],
            self::VILLA => ['laundry', 'fnb', 'kitchen', 'maintenance', 'hr', 'inventory'],
        ];
    }

    public static function isProfile(string $value): bool
    {
        return array_key_exists($value, self::presets());
    }
}
