<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/**
 * Pages of a department that most hotels never use. A property switches each one on when it needs it (Set-up → How the property works); until then the menu leaves it out, so the people
 * of a small hotel do not walk past screens that are not theirs. It only decides what the menu offers: the page itself, its permission and the data stay as they are.
 */
final class OptionalFeatures
{
    /** @var list<string> */
    public const ALL = ['waitlist', 'groups', 'day-use', 'activities', 'gift-cards', 'commissions', 'door-locks', 'foreign-currency'];

    public static function isFeature(string $key): bool
    {
        return in_array($key, self::ALL, true);
    }
}
