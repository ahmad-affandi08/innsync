<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Groups\GroupBookingService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class GroupHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $type;

    private string $plan;

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
        $this->signIn(self::A, [GroupBookingService::MANAGE_PERMISSION, ReservationService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->type = $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/rooms', ['number' => '102', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(string $mode = 'master'): array
    {
        return [
            'name' => 'Wedding Rahma', 'booker_name' => 'Rahma Putri', 'source' => 'phone', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'billing_mode' => $mode, 'status' => 'confirmed',
            'rooms' => [['room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'adults' => 2, 'children' => 0, 'guest_name' => 'Siti']],
        ];
    }

    public function test_a_group_is_booked_listed_shown_and_extended_over_http(): void
    {
        $this->postJson('/front-office/groups', $this->payload())->assertStatus(400);
        $made = $this->postJson('/front-office/groups', $this->payload(), ['Idempotency-Key' => 'group-http-key-0001'])->assertCreated()->assertJsonPath('group.group.number', 'GRP-000001')->assertJsonPath('group.members.0.guest_name', 'Siti')->json('group');
        $again = $this->postJson('/front-office/groups', $this->payload(), ['Idempotency-Key' => 'group-http-key-0001'])->assertCreated()->json('group');
        self::assertSame($made['group']['id'], $again['group']['id']);
        self::assertSame(1, DB::table('reservation_groups')->count());
        $this->postJson('/front-office/groups', [...$this->payload('shared')], ['Idempotency-Key' => 'group-http-key-0002'])->assertStatus(422);
        $this->postJson('/front-office/groups', [...$this->payload(), 'rooms' => []], ['Idempotency-Key' => 'group-http-key-0003'])->assertStatus(422);

        $this->get('/front-office/groups')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/groups')->has('overview.groups', 1)->where('overview.may.manage', true)->has('lookups.types', 1));
        $this->get('/front-office/groups?query=nomatch')->assertInertia(fn (Assert $p) => $p->has('overview.groups', 0));
        $this->get('/front-office/groups?query=wedding')->assertInertia(fn (Assert $p) => $p->has('overview.groups', 1));
        $this->get("/front-office/groups/{$made['group']['id']}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/group')->has('group.members', 1)->where('group.master.number', fn ($n) => is_string($n)));

        $this->postJson("/front-office/groups/{$made['group']['id']}/rooms", ['rooms' => $this->payload()['rooms']], ['Idempotency-Key' => 'group-http-key-0004'])->assertOk()->assertJsonPath('group.totals.rooms', 2);
        $member = DB::table('reservation_group_members')->where('line', 2)->value('reservation_id');
        $this->get("/front-office/reservations/{$member}")->assertInertia(fn (Assert $p) => $p->where('group.number', 'GRP-000001')->where('group.billing_mode', 'master'));
    }

    public function test_the_group_rights_are_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [GroupBookingService::VIEW_PERMISSION]);

        $this->get('/front-office/groups')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false)->where('lookups', null));
        $this->postJson('/front-office/groups', $this->payload(), ['Idempotency-Key' => 'group-http-key-0005'])->assertForbidden();
        $this->postJson('/front-office/groups/'.str_repeat('0', 26).'/rooms', ['rooms' => $this->payload()['rooms']], ['Idempotency-Key' => 'group-http-key-0006'])->assertForbidden();
    }
}
