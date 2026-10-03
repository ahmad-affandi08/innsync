<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Reporting\Application\DashboardService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-DSH-006, -007, -009: the cards of spending, of low stock by department and of maintenance, each only for people who may see what is behind it. */
final class DashboardOperationsCardsTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

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
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function person(array $permissions): string
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY, ['reporting.dashboard.view', ...$permissions]);

        return strtolower((string) $user->getKey());
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(string $actor): array
    {
        return array_column(app(DashboardService::class)->snapshot($this->property(), $actor, null, null, null)['cards'], null, 'key');
    }

    private function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function test_a_person_sees_only_the_cards_of_what_they_may_see(): void
    {
        $none = array_keys($this->cards($this->person([])));
        self::assertNotContains('spend', $none);
        self::assertNotContains('stock', $none);
        self::assertNotContains('maintenance', $none);

        $all = array_keys($this->cards($this->person(['finance.payable.view', 'inventory.stock.view', 'maintenance.work.manage'])));
        self::assertContains('spend', $all);
        self::assertContains('stock', $all);
        self::assertContains('maintenance', $all);
    }

    public function test_the_spending_card_adds_up_what_was_paid_what_is_owed_and_what_falls_due(): void
    {
        $today = (string) DB::table('property_settings')->value('business_date');
        $user = $this->adminId;
        $payable = function (string $doc, string $due, int $amount) use ($today): string {
            $id = $this->id();
            DB::table('ap_payables')->insert([
                'id' => $id, 'property_id' => self::PROPERTY, 'supplier_id' => $this->id(), 'supplier_code' => 'SUP', 'supplier_name' => 'Fresh Farm', 'source_type' => 'invoice', 'source_id' => $this->id(), 'source_number' => $doc,
                'document_number' => $doc, 'issued_on' => $today, 'due_date' => $due, 'business_date' => $today, 'amount_minor' => $amount, 'currency' => 'IDR', 'event_id' => $this->id(), 'occurred_at' => now(), 'created_at' => now(),
            ]);

            return $id;
        };
        $late = $payable('INV-1', date('Y-m-d', strtotime($today.' -2 days')), 500_000);
        $payable('INV-2', date('Y-m-d', strtotime($today.' +3 days')), 1_000_000);
        $payable('INV-3', date('Y-m-d', strtotime($today.' +20 days')), 250_000);
        DB::table('ap_payments')->insert([
            'id' => $this->id(), 'property_id' => self::PROPERTY, 'number' => 'PAY-1', 'payable_id' => $late, 'supplier_id' => $this->id(), 'amount_minor' => 200_000, 'method' => 'transfer', 'paid_on' => $today, 'status' => 'paid',
            'created_by' => $user, 'business_date' => $today, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $card = $this->cards($this->person(['finance.payable.view']))['spend']['values'];
        self::assertSame([200_000, 1_550_000, 300_000, 1_000_000, 1_250_000], [$card['paid_minor'], $card['owed_minor'], $card['overdue_minor'], $card['due7_minor'], $card['due30_minor']]);
        self::assertSame(['INV-2', 'INV-3'], array_column($card['upcoming'], 'document'));
    }

    public function test_the_stock_card_counts_items_below_their_minimum_by_department(): void
    {
        $today = (string) DB::table('property_settings')->value('business_date');
        $category = $this->id();
        DB::table('inventory_categories')->insert(['id' => $category, 'property_id' => self::PROPERTY, 'code' => 'GEN', 'name' => 'General', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $location = $this->id();
        DB::table('inventory_locations')->insert(['id' => $location, 'property_id' => self::PROPERTY, 'code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([['RICE', 'kitchen', 5_000], ['OIL', 'kitchen', 1_000], ['LIME', 'bar', 0], ['SOAP', 'housekeeping', 90_000]] as [$code, $dept, $balance]) {
            $item = $this->id();
            DB::table('inventory_items')->insert(['id' => $item, 'property_id' => self::PROPERTY, 'code' => $code, 'name' => $code, 'category_id' => $category, 'department' => $dept, 'base_unit' => 'PCS', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_stock_limits')->insert(['id' => $this->id(), 'property_id' => self::PROPERTY, 'item_id' => $item, 'location_id' => $location, 'min_milli' => 10_000, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

            if ($balance > 0) {
                DB::table('stock_movements')->insert(['id' => $this->id(), 'property_id' => self::PROPERTY, 'item_id' => $item, 'location_id' => $location, 'kind' => 'receipt', 'unit' => 'PCS', 'unit_qty_milli' => $balance, 'factor_milli' => 1000, 'base_qty_milli' => $balance, 'business_date' => $today, 'posted_by' => $this->adminId, 'created_at' => now()]);
            }
        }

        $card = $this->cards($this->person(['inventory.stock.view']))['stock']['values']['departments'];
        self::assertSame([['bar', 1], ['kitchen', 2]], array_map(static fn (array $d): array => [$d['department'], $d['count']], $card));
        self::assertSame(['OIL · MAIN', 'RICE · MAIN'], $card[1]['items']);
    }

    public function test_the_maintenance_card_counts_open_done_late_and_the_rooms_out_of_order(): void
    {
        $today = (string) DB::table('property_settings')->value('business_date');
        $now = $this->clock->nowUtc();
        $order = function (string $number, string $status, string $due, ?string $done = null) use ($now): void {
            DB::table('maintenance_work_orders')->insert([
                'id' => $this->id(), 'property_id' => self::PROPERTY, 'number' => $number, 'title' => 'Fix '.$number, 'category' => 'plumbing', 'reporter_department' => 'fnb', 'priority' => 'normal', 'status' => $status,
                'reported_at' => $now, 'area' => 'Kitchen', 'reported_by' => $this->adminId, 'due_at' => $due, 'done_at' => $done, 'done_photo_file_id' => $done === null ? null : $this->id(), 'lock_version' => 0, 'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        };
        $order('WO-1', 'open', $now->modify('-3 hours')->format('Y-m-d H:i:s'));
        $order('WO-2', 'in_progress', $now->modify('+5 hours')->format('Y-m-d H:i:s'));
        $order('WO-3', 'done', $now->modify('-1 day')->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));
        DB::table('room_blocks')->insert(['id' => $this->id(), 'property_id' => self::PROPERTY, 'room_id' => $this->roomIds[0], 'kind' => 'out_of_order', 'start_date' => $today, 'end_date' => $today, 'reason' => 'Leak', 'created_by' => $this->adminId, 'created_at' => $now->format('Y-m-d H:i:s')]);

        $card = $this->cards($this->person(['maintenance.work.manage']))['maintenance']['values'];
        self::assertSame([2, 1, 1], [$card['open'], $card['done_today'], $card['overdue']]);
        self::assertCount(1, $card['out_of_order']);
    }
}
