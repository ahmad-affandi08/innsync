<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/** The screen a person who works in only one department lands on after signing in. The keys are the menu keys of `ModuleAccessMap`. Everyone else starts at the home page. */
final class DepartmentEntries
{
    private const LANDING = [
        'front-office' => '/front-office/today',
        'housekeeping' => '/housekeeping/my-rooms',
        'laundry' => '/laundry',
        'fnb' => '/fnb/pos',
        'kitchen' => '/kitchen',
        'maintenance' => '/maintenance',
        'hr' => '/hr/me',
        'inventory' => '/inventory/stock',
        'finance' => '/finance/payables',
        'dashboard' => '/dashboard',
    ];

    public static function landing(string $moduleKey): ?string
    {
        return self::LANDING[$moduleKey] ?? null;
    }
}
