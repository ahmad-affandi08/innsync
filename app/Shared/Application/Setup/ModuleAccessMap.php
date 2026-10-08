<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/**
 * Which parts of the menu a person is offered, from the permissions they hold (owner decision 2026-10-07: a role sees the work of that role). A menu entry is
 * offered when the person holds at least one permission that begins with one of its prefixes. This only decides what is shown: every page still checks the permission
 * itself on the server, so a hidden entry is not a security boundary.
 */
final class ModuleAccessMap
{
    /** Entries everyone signed in sees: the home page and the approvals inbox (each person's own requests and what they may decide). */
    public const ALWAYS = ['home', 'approvals'];

    /** @return array<string, list<string>> menu key => permission prefixes */
    public static function prefixes(): array
    {
        return [
            'front-office' => ['front-office.', 'guest.'],
            'housekeeping' => ['housekeeping.'],
            'laundry' => ['laundry.'],
            'fnb' => ['fnb.'],
            'kitchen' => ['kitchen.'],
            'maintenance' => ['maintenance.'],
            'hr' => ['hr.'],
            'inventory' => ['inventory.', 'purchasing.'],
            'finance' => ['finance.'],
            'dashboard' => ['reporting.dashboard.'],
            'reports' => ['reporting.'],
            'property' => ['property.', 'identity.', 'privacy.', 'offline.', 'integration.'],
        ];
    }

    /**
     * @param  list<string>  $permissions  codes the person holds
     * @return list<string> menu keys to offer
     */
    public static function forPermissions(array $permissions): array
    {
        $keys = self::ALWAYS;

        foreach (self::prefixes() as $key => $prefixes) {
            foreach ($permissions as $code) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($code, $prefix)) {
                        $keys[] = $key;

                        continue 3;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
