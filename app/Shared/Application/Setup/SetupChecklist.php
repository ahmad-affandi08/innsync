<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/**
 * The first-time set-up of a property as an ordered list (owner instruction 2026-10-07: setup was spread over some thirty screens with no order and no sign of
 * what is still missing). Each step says where to do it, whether the hotel cannot work without it (`required`) and, from counts read out of the data, whether it is done.
 * A step that is not required is a module the hotel may not use (laundry, kitchen, HR): it is listed, never counted against progress.
 *
 * Pure: the facts come from `SetupFacts`, so the order and the rules are tested without a database.
 */
final class SetupChecklist
{
    /**
     * @param  array<string, int>  $facts
     * @return list<array{key: string, group: string, required: bool, href: string, done: bool, counts: array<string, int>}>
     */
    public static function evaluate(array $facts): array
    {
        $f = static fn (string $key): int => (int) ($facts[$key] ?? 0);

        return [
            self::step('settings', 'property', true, '/property/settings', $f('settings') > 0, []),
            self::step('business_date', 'property', true, '/property/settings', $f('business_date') > 0, []),
            self::step('rooms', 'property', true, '/property/rooms', $f('room_types') > 0 && $f('rooms') > 0, ['types' => $f('room_types'), 'rooms' => $f('rooms')]),
            self::step('rates', 'property', true, '/property/rates', $f('rate_plans') > 0 && $f('rate_periods') > 0, ['plans' => $f('rate_plans'), 'prices' => $f('rate_periods')]),
            self::step('tax', 'property', true, '/property/tax', $f('charge_schemes') > 0, []),
            self::step('booking_policies', 'property', false, '/property/policies', $f('booking_policies') > 0, ['policies' => $f('booking_policies')]),
            self::step('roles', 'people', true, '/access/roles', $f('roles') > 0, ['roles' => $f('roles')]),
            self::step('people', 'people', true, '/access/users', $f('people') > 1, ['people' => $f('people')]),
            self::step('approvals', 'people', true, '/approvals/policies', $f('approvals_total') > 0 && $f('approvals_missing') === 0, ['missing' => $f('approvals_missing'), 'total' => $f('approvals_total')]),
            self::step('fnb', 'operations', false, '/fnb/outlets', $f('fnb_outlets') > 0 && $f('fnb_items') > 0, ['outlets' => $f('fnb_outlets'), 'items' => $f('fnb_items')]),
            self::step('laundry', 'operations', false, '/laundry/prices', $f('laundry_prices') > 0, ['items' => $f('laundry_prices')]),
            self::step('housekeeping', 'operations', false, '/housekeeping/checklists/templates', $f('hk_templates') > 0, ['templates' => $f('hk_templates')]),
            self::step('inventory', 'operations', false, '/inventory/suppliers', $f('inventory_items') > 0 && $f('suppliers') > 0, ['items' => $f('inventory_items'), 'suppliers' => $f('suppliers')]),
            self::step('hr', 'operations', false, '/hr/employees', $f('employees') > 0, ['employees' => $f('employees')]),
            self::step('finance', 'operations', false, '/finance/accounts', $f('expense_accounts') > 0, ['accounts' => $f('expense_accounts')]),
        ];
    }

    /**
     * @param  list<array{required: bool, done: bool}>  $steps
     * @return array{done: int, total: int}
     */
    public static function progress(array $steps): array
    {
        $required = array_filter($steps, static fn (array $s): bool => $s['required']);

        return ['done' => count(array_filter($required, static fn (array $s): bool => $s['done'])), 'total' => count($required)];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{key: string, group: string, required: bool, href: string, done: bool, counts: array<string, int>}
     */
    private static function step(string $key, string $group, bool $required, string $href, bool $done, array $counts): array
    {
        return ['key' => $key, 'group' => $group, 'required' => $required, 'href' => $href, 'done' => $done, 'counts' => $counts];
    }
}
