<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use App\Shared\Application\Setup\ModuleAccessMap;
use PHPUnit\Framework\TestCase;

final class ModuleAccessMapTest extends TestCase
{
    public function test_a_person_with_no_permissions_is_offered_only_home_and_the_approvals_inbox(): void
    {
        self::assertEqualsCanonicalizing(['home', 'approvals'], ModuleAccessMap::forPermissions([]));
    }

    public function test_a_receptionist_is_offered_the_front_desk_and_what_they_touch_not_hr_or_finance(): void
    {
        $keys = ModuleAccessMap::forPermissions(['front-office.reservation.view', 'guest.checkin.manage', 'housekeeping.view', 'laundry.order.intake']);

        self::assertContains('front-office', $keys);
        self::assertContains('housekeeping', $keys);
        self::assertContains('laundry', $keys);
        self::assertNotContains('hr', $keys);
        self::assertNotContains('finance', $keys);
        self::assertNotContains('property', $keys);
    }

    public function test_the_dashboard_and_the_reports_have_their_own_permissions(): void
    {
        self::assertContains('dashboard', ModuleAccessMap::forPermissions(['reporting.dashboard.view']));
        self::assertContains('reports', ModuleAccessMap::forPermissions(['reporting.dashboard.view']), 'reporting.* opens the reports area too');
        self::assertNotContains('dashboard', ModuleAccessMap::forPermissions(['reporting.revenue.view']));
    }

    public function test_every_permission_of_the_catalog_opens_something(): void
    {
        $catalog = DatabaseDefaultRoleInstaller::catalog();
        $orphans = array_filter($catalog, static fn (string $code): bool => array_diff(ModuleAccessMap::forPermissions([$code]), ModuleAccessMap::ALWAYS) === []);

        self::assertSame([], array_values($orphans), 'a permission that opens no menu entry leaves its holder with no way in');
    }
}
