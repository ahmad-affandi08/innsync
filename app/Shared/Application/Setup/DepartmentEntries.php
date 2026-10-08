<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/**
 * The page each department's staff start from: the address to give them and the screen a person who works in only one department lands on after signing in. The keys are the
 * menu keys of `ModuleAccessMap`. A person who works across several departments starts at the home page as before.
 */
final class DepartmentEntries
{
    /** @return list<array{key: string, label: string, about: string, paths: list<array{path: string, label: string}>}> */
    public static function all(): array
    {
        return [
            ['key' => 'front-office', 'label' => 'dl.fo', 'about' => 'dl.fo.about', 'paths' => [['path' => '/front-office/today', 'label' => 'dl.fo.today'], ['path' => '/front-office/room-board', 'label' => 'dl.fo.board'], ['path' => '/front-office/room-calendar', 'label' => 'dl.fo.calendar']]],
            ['key' => 'housekeeping', 'label' => 'dl.hk', 'about' => 'dl.hk.about', 'paths' => [['path' => '/housekeeping/my-rooms', 'label' => 'dl.hk.mine'], ['path' => '/housekeeping', 'label' => 'dl.hk.board']]],
            ['key' => 'laundry', 'label' => 'dl.ldy', 'about' => 'dl.ldy.about', 'paths' => [['path' => '/laundry', 'label' => 'dl.ldy.queue']]],
            ['key' => 'fnb', 'label' => 'dl.fnb', 'about' => 'dl.fnb.about', 'paths' => [['path' => '/fnb/pos', 'label' => 'dl.fnb.pos'], ['path' => '/fnb/register', 'label' => 'dl.fnb.register']]],
            ['key' => 'kitchen', 'label' => 'dl.kitchen', 'about' => 'dl.kitchen.about', 'paths' => [['path' => '/kitchen', 'label' => 'dl.kitchen.board']]],
            ['key' => 'maintenance', 'label' => 'dl.mtc', 'about' => 'dl.mtc.about', 'paths' => [['path' => '/maintenance', 'label' => 'dl.mtc.orders']]],
            ['key' => 'hr', 'label' => 'dl.hr', 'about' => 'dl.hr.about', 'paths' => [['path' => '/hr/me', 'label' => 'dl.hr.me'], ['path' => '/hr/employees', 'label' => 'dl.hr.staff']]],
            ['key' => 'inventory', 'label' => 'dl.inv', 'about' => 'dl.inv.about', 'paths' => [['path' => '/inventory/stock', 'label' => 'dl.inv.stock']]],
            ['key' => 'finance', 'label' => 'dl.fin', 'about' => 'dl.fin.about', 'paths' => [['path' => '/finance/payables', 'label' => 'dl.fin.payables']]],
            ['key' => 'dashboard', 'label' => 'dl.dash', 'about' => 'dl.dash.about', 'paths' => [['path' => '/dashboard', 'label' => 'dl.dash.view']]],
        ];
    }

    /** Where a person who works in this one department lands, or null when the department has no page of its own to start from. */
    public static function landing(string $moduleKey): ?string
    {
        foreach (self::all() as $entry) {
            if ($entry['key'] === $moduleKey) {
                return $entry['paths'][0]['path'];
            }
        }

        return null;
    }
}
