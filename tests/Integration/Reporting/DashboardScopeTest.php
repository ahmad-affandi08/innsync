<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Reporting\Application\DashboardService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\DepartmentScope;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-DSH-022, NFR-06: the dashboard shows each person only the numbers their scope allows (docs/OPERATIONS/SCOPE-MODEL.md). */
final class DashboardScopeTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private const REST = '01arz3ndektsv4rrffq69g5fo1';

    private const BAR = '01arz3ndektsv4rrffq69g5fo2';

    private const VIEW = 'reporting.dashboard.view';

    private const REVENUE = 'reporting.revenue.view';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();

        foreach ([[self::REST, 'REST', 'Restaurant'], [self::BAR, 'BAR', 'Bar']] as [$id, $code, $name]) {
            DB::table('fnb_outlets')->insert(['id' => $id, 'property_id' => self::PROPERTY, 'code' => $code, 'name' => $name, 'kind' => 'restaurant', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Two outlets sold for cash on the business date: 100,000 at the restaurant and 40,000 at the bar (base, before service charge and tax).
        $i = 0;

        foreach ([[self::REST, 'REST', 100_000], [self::BAR, 'BAR', 40_000]] as [$id, $code, $base]) {
            $i++;
            DB::table('fin_pos_sales')->insert([
                'id' => '01arz3ndektsv4rrffq69g5fs'.$i, 'property_id' => self::PROPERTY, 'bill_id' => '01arz3ndektsv4rrffq69g5fb'.$i, 'bill_number' => 'B-'.$i, 'outlet_id' => $id, 'outlet_code' => $code,
                'source' => 'pos_'.strtolower($code), 'business_date' => '2026-10-01', 'currency' => 'IDR', 'base_minor' => $base, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => $base,
                'cash_minor' => $base, 'room_minor' => 0, 'event_id' => '01arz3ndektsv4rrffq69g5fe'.$i, 'occurred_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function scoped(array $permissions, string $type, string $scopeId): string
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY, $permissions, $type, $scopeId);

        return (string) $user->getKey();
    }

    private function department(string $department): string
    {
        return DepartmentScope::idFor(PropertyId::fromString(self::PROPERTY), $department);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $actor): array
    {
        $snapshot = app(DashboardService::class)->snapshot(PropertyId::fromString(self::PROPERTY), $actor, 'today', null, null);

        return [...$snapshot, 'cards' => array_column($snapshot['cards'], null, 'key')];
    }

    public function test_the_whole_property_sees_every_card_and_all_the_revenue(): void
    {
        $snapshot = $this->snapshot($this->analystId);

        self::assertTrue($snapshot['scope']['property']);
        self::assertSame(140_000, $snapshot['cards']['revenue']['values']['net']['total']);
        self::assertFalse($snapshot['cards']['revenue']['limited']);
        self::assertArrayHasKey('occupancy', $snapshot['cards']);
        self::assertArrayHasKey('outlet_hours', $snapshot['cards']);
    }

    public function test_a_head_of_an_outlet_sees_only_the_sales_of_that_outlet(): void
    {
        $actor = $this->scoped([self::VIEW, self::REVENUE], 'outlet', self::REST);
        $snapshot = $this->snapshot($actor);

        self::assertSame(['revenue', 'outlet_hours'], array_keys($snapshot['cards']));
        self::assertFalse($snapshot['scope']['property']);
        self::assertSame([self::REST], $snapshot['scope']['outlets']);
        self::assertTrue($snapshot['cards']['revenue']['limited']);
        self::assertSame(100_000, $snapshot['cards']['revenue']['values']['net']['total'], 'the bar is not part of it');
        self::assertSame(0, $snapshot['cards']['revenue']['values']['room']['total']);
        self::assertSame(['REST'], array_column($snapshot['cards']['outlet_hours']['values']['outlets'], 'code'));
        self::assertSame([], $snapshot['alerts']);
        // The comparison periods are limited the same way.
        self::assertSame(0, $snapshot['cards']['revenue']['values']['previous']['net_minor']);
    }

    public function test_a_head_of_fnb_sees_the_sales_of_every_outlet_and_a_head_of_the_front_office_sees_the_front_desk(): void
    {
        $fnb = $this->snapshot($this->scoped([self::VIEW, self::REVENUE], 'department', $this->department('fnb')));
        self::assertSame(140_000, $fnb['cards']['revenue']['values']['net']['total']);
        self::assertSame(['BAR', 'REST'], array_column($fnb['cards']['outlet_hours']['values']['outlets'], 'code'));
        self::assertArrayNotHasKey('occupancy', $fnb['cards']);
        self::assertArrayNotHasKey('products', $fnb['cards'], 'products mix outlets, so only the whole property sees them');

        $office = $this->snapshot($this->scoped([self::VIEW, self::REVENUE], 'department', $this->department('front_office')));
        self::assertSame(['occupancy', 'movements', 'activity', 'revenue', 'arrivals'], array_keys($office['cards']));
        self::assertSame(0, $office['cards']['revenue']['values']['net']['total'], 'the sales of the outlets are not the front desk\'s');
        self::assertArrayNotHasKey('outlet_hours', $office['cards']);
    }

    public function test_the_staff_card_of_a_head_of_department_holds_only_that_department(): void
    {
        $kitchen = $this->scoped([self::VIEW, 'hr.employee.view'], 'department', $this->department('kitchen'));
        $card = $this->snapshot($kitchen)['cards']['staff'];

        self::assertTrue($card['limited']);
        self::assertNull($card['values']['off'], 'the day off is a count of the property, so it is left out');
        self::assertSame([], $card['values']['groups']);

        // Without the human resource permission in that scope, there is no staff card at all.
        self::assertArrayNotHasKey('staff', $this->snapshot($this->scoped([self::VIEW], 'department', $this->department('kitchen')))['cards']);
    }

    public function test_a_grant_to_the_scope_of_another_property_or_none_shows_nothing(): void
    {
        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::PROPERTY, []);

        try {
            $this->snapshot((string) $nobody->getKey());
            self::fail('A person with no grant saw the dashboard.');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }

        // An outlet that is not of this property is no outlet here: the card is there for the right, and empty.
        $actor = $this->scoped([self::VIEW, self::REVENUE], 'outlet', '01arz3ndektsv4rrffq69g5fo9');
        self::assertSame(0, $this->snapshot($actor)['cards']['revenue']['values']['net']['total']);

        // A department scope id that is not a department of this property gives nothing, so it is the same as no grant.
        $stray = $this->scoped([self::VIEW], 'department', '01arz3ndektsv4rrffq69g5fo9');

        try {
            $this->snapshot($stray);
            self::fail('A grant to an unknown department showed the dashboard.');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }
    }
}
