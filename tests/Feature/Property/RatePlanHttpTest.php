<?php

declare(strict_types=1);

namespace Tests\Feature\Property;

use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class RatePlanHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
    }

    private function manager(): string
    {
        $this->signIn(self::A, [RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION]);

        return $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 0, 'reason' => 'x'])->json('type.id');
    }

    public function test_a_manager_sets_up_a_plan_prices_restrictions_and_tax_and_gets_a_bookable_quote(): void
    {
        $type = $this->manager();

        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'Regional regulation'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'bar', 'name' => 'BAR', 'kind' => 'public', 'inclusions' => 'Breakfast', 'prices_include_charges' => false, 'reason' => 'x'])->assertCreated()->json('plan');
        $this->postJson("/property/rate-plans/{$plan['id']}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2026-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'Season'])->assertCreated();
        $this->postJson("/property/rate-plans/{$plan['id']}/restrictions", ['room_type_id' => null, 'from' => '2026-12-24', 'to' => '2026-12-26', 'min_stay' => 2, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'stop_sell' => false, 'reason' => 'Holiday'])->assertCreated();

        $ok = $this->postJson("/property/rate-plans/{$plan['id']}/quote", ['room_type_id' => $type, 'arrival' => '2026-10-10', 'departure' => '2026-10-12'])->assertOk()->json('quote');
        self::assertTrue($ok['bookable']);
        self::assertSame(242_000_000, $ok['total_minor']);
        self::assertCount(2, $ok['nights']);

        $short = $this->postJson("/property/rate-plans/{$plan['id']}/quote", ['room_type_id' => $type, 'arrival' => '2026-12-24', 'departure' => '2026-12-25'])->assertOk()->json('quote');
        self::assertFalse($short['bookable']);
        self::assertSame('min_stay', $short['violations'][0]['code']);
        self::assertSame(2, $short['violations'][0]['value']);

        $this->get("/property/rates?plan={$plan['id']}")->assertInertia(fn (Assert $page) => $page
            ->component('property/pages/rate-plans')->has('plans', 1)->has('types', 1)->has('selected.periods', 1)->has('selected.restrictions', 1));
        $this->get('/property/tax')->assertInertia(fn (Assert $page) => $page->component('property/pages/charge-schemes')->has('schemes', 1));
    }

    public function test_validation_and_domain_refusals_use_the_standard_envelope(): void
    {
        $type = $this->manager();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'bar', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan');

        $this->postJson('/property/rate-plans', ['code' => 'bar', 'name' => 'Dup', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->postJson("/property/rate-plans/{$plan['id']}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2026-10-31', 'weekday_mask' => 127, 'nightly_minor' => 1, 'reason' => 'x'])->assertCreated();
        $this->postJson("/property/rate-plans/{$plan['id']}/prices", ['room_type_id' => $type, 'from' => '2026-10-15', 'to' => '2026-11-15', 'weekday_mask' => 127, 'nightly_minor' => 1, 'reason' => 'x'])->assertStatus(422);
        $this->postJson("/property/rate-plans/{$plan['id']}/quote", ['room_type_id' => $type, 'arrival' => '2026-10-12', 'departure' => '2026-10-10'])->assertStatus(422);
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10.555', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertStatus(422);
    }

    public function test_a_person_without_rate_permission_cannot_read_or_change_prices(): void
    {
        $this->signIn(self::A, ['front-office.reservation.view']);

        $this->get('/property/rates')->assertForbidden();
        $this->postJson('/property/rate-plans', ['code' => 'bar', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->get('/property/tax')->assertForbidden();
        self::assertSame(0, DB::table('rate_plans')->count());
    }

    public function test_price_changes_need_a_recent_password_confirmation_but_a_quote_does_not(): void
    {
        $type = $this->manager();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'bar', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan');
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);

        $this->postJson("/property/rate-plans/{$plan['id']}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2026-10-31', 'weekday_mask' => 127, 'nightly_minor' => 1, 'reason' => 'x'])->assertStatus(423);
        $this->postJson("/property/rate-plans/{$plan['id']}/quote", ['room_type_id' => $type, 'arrival' => '2026-10-10', 'departure' => '2026-10-11'])->assertOk();
        self::assertSame(0, DB::table('rate_periods')->count());
    }

    public function test_a_price_is_replaced_by_a_new_one_with_a_reason_and_the_old_one_stays_as_history(): void
    {
        $type = $this->manager();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '0', 'tax_rate' => '0', 'tax_on_service_charge' => false, 'reason' => 'None'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'bar', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->assertCreated()->json('plan');
        $this->postJson("/property/rate-plans/{$plan['id']}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2026-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'Season'])->assertCreated();
        $old = (string) DB::table('rate_periods')->value('id');
        $quote = fn (): int => (int) $this->postJson("/property/rate-plans/{$plan['id']}/quote", ['room_type_id' => $type, 'arrival' => '2026-10-10', 'departure' => '2026-10-11'])->assertOk()->json('quote.total_minor');
        self::assertSame(100_000_000, $quote());

        $this->postJson("/property/rate-prices/{$old}/reprice", ['nightly_minor' => 120_000_000, 'reason' => ''])->assertStatus(422);
        $this->postJson("/property/rate-prices/{$old}/reprice", ['nightly_minor' => -1, 'reason' => 'Mistake'])->assertStatus(422);
        $this->postJson('/property/rate-prices/01arz3ndektsv4rrffq69g5fzz/reprice', ['nightly_minor' => 120_000_000, 'reason' => 'Raise'])->assertNotFound();

        $new = (string) $this->postJson("/property/rate-prices/{$old}/reprice", ['nightly_minor' => 120_000_000, 'reason' => 'Peak season'])->assertOk()->json('id');
        self::assertNotSame($old, $new);
        self::assertSame(120_000_000, $quote());
        self::assertSame(2, DB::table('rate_periods')->count(), 'the old price is kept as history');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'rate_period.repriced')->where('aggregate_id', $new)->count());

        // The price that was replaced is history: it is no longer a price to change.
        $this->postJson("/property/rate-prices/{$old}/reprice", ['nightly_minor' => 130_000_000, 'reason' => 'Again'])->assertNotFound();
        self::assertSame(120_000_000, $quote());

        // Someone without the right to manage rates may not.
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);
        $this->postJson("/property/rate-prices/{$new}/reprice", ['nightly_minor' => 1, 'reason' => 'Nope'])->assertForbidden();
    }
}
