<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-020 to -025: the mini bar of a room is checked by scanning it and what was consumed goes to the folio; what to refill is listed; every check is kept; and room service orders are delivered by the time promised. */
final class MinibarAndRoomServiceHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $attendant;

    private UserRecord $waiter;

    private UserRecord $nobody;

    private AdjustableClock $clock;

    /** @var array<string, string> */
    private array $item = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new AdjustableClock('2026-10-03 03:00:00');
        $this->app->instance(Clock::class, $this->clock);

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, FnbAccess::MINIBAR_MANAGE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->attendant = $make([FnbAccess::MINIBAR_OPERATE]);
        $this->waiter = $make([FnbAccess::POS_OPERATE]);
        $this->nobody = $make(['housekeeping.view']);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertCreated();
        $this->menu();

        foreach ([['WATER', 'Water', 1_500_000, 2], ['COLA', 'Cola', 3_000_000, 2], ['NUTS', 'Nuts', 4_500_000, 1]] as [$code, $name, $price, $par]) {
            $this->postJson('/fnb/minibar/items', ['code' => $code, 'name' => $name, 'price_minor' => $price, 'par_qty' => $par])->assertCreated();
        }

        foreach (DB::table('fnb_minibar_items')->get() as $i) {
            $this->item[$i->code] = $i->id;
        }
    }

    private function check(array $lines, string $code = '101', int $status = 201): ?array
    {
        $r = $this->postJson('/fnb/minibar/checks', ['room_code' => $code, 'lines' => $lines], $this->key())->assertStatus($status);

        return $status === 201 ? $r->json() : null;
    }

    /** @return array<string, mixed> */
    private function page(string $query = ''): array
    {
        return $this->get('/fnb/minibar'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_the_items_are_set_by_the_manager_with_a_price_and_how_many_a_room_holds(): void
    {
        $this->postJson('/fnb/minibar/items', ['code' => 'water', 'name' => 'Again', 'price_minor' => 1, 'par_qty' => 1])->assertStatus(409);
        $this->postJson('/fnb/minibar/items', ['code' => 'bad code', 'name' => 'x', 'price_minor' => 1, 'par_qty' => 1])->assertStatus(422);
        $this->postJson('/fnb/minibar/items', ['code' => 'JUICE', 'name' => 'Juice', 'price_minor' => 0, 'par_qty' => 1])->assertStatus(422);
        $this->postJson('/fnb/minibar/items', ['code' => 'JUICE', 'name' => 'Juice', 'price_minor' => 1, 'par_qty' => 100])->assertStatus(422);
        $this->postJson("/fnb/minibar/items/{$this->item['COLA']}", ['name' => 'Cola can', 'price_minor' => 3_500_000, 'par_qty' => 3, 'lock_version' => 4])->assertStatus(409);
        $this->postJson("/fnb/minibar/items/{$this->item['COLA']}", ['name' => 'Cola can', 'price_minor' => 3_500_000, 'par_qty' => 3, 'lock_version' => 0])->assertOk();
        $this->postJson("/fnb/minibar/items/{$this->item['NUTS']}/active", ['active' => false, 'lock_version' => 0])->assertOk();
        $this->postJson("/fnb/minibar/items/{$this->item['NUTS']}/active", ['active' => false, 'lock_version' => 1])->assertStatus(409);

        $this->actAs($this->attendant);
        $this->postJson('/fnb/minibar/items', ['code' => 'JUICE', 'name' => 'Juice', 'price_minor' => 1, 'par_qty' => 1])->assertForbidden();
        self::assertSame(['COLA', 'WATER'], collect($this->page()['overview']['items'])->pluck('code')->sort()->values()->all(), 'a retired item is not offered');
        $this->actAs($this->nobody);
        $this->get('/fnb/minibar')->assertForbidden();
    }

    public function test_scanning_a_room_shows_the_guest_and_what_it_holds_and_the_check_charges_the_folio_and_keeps_the_history(): void
    {
        $this->actAs($this->attendant);
        $scan = $this->page('?code=room:101')['scanned'];
        self::assertSame(['101', 'Budi Santoso', true], [$scan['room']['number'], $scan['guest_name'], $scan['in_house']]);
        self::assertSame([2, 1, 2], collect($scan['items'])->sortBy('code')->pluck('held')->values()->all(), 'a room holds its par until checked');
        self::assertNotNull($this->page('?code=999')['scan_error']);

        // The guest had one water and two colas; the attendant puts back one water and nothing else.
        $check = $this->check([['item_id' => $this->item['WATER'], 'consumed' => 1, 'refilled' => 1], ['item_id' => $this->item['COLA'], 'consumed' => 2, 'refilled' => 0]]);
        self::assertSame([1_500_000 + 6_000_000, true], [$check['consumed_minor'], $check['posted']]);
        self::assertCount(1, $this->charged);
        self::assertSame(['fnb', 7_500_000, 'fnb_minibar', '01arz3ndektsv4rrffq69g5fc3'], [$this->charged[0]['scope'], $this->charged[0]['quoted'], $this->charged[0]['source'], $this->charged[0]['reservation']]);
        self::assertSame($check['id'], $this->charged[0]['ref'], 'the same check is posted once however often it is sent');
        self::assertSame(['01arz3ndektsv4rrffq69g5fc4', intdiv(7_500_000 * 121, 100)], [DB::table('fnb_minibar_checks')->value('folio_posting_id'), (int) DB::table('fnb_minibar_checks')->value('charged_total_minor')]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.minibar.consumed')->count());

        // What the room holds now, and what to refill before the next shift.
        $held = array_column($this->page('?code=101')['scanned']['items'], 'held', 'code');
        self::assertSame([2, 0, 1], [$held['WATER'], $held['COLA'], $held['NUTS']]);
        $refill = $this->page()['refill'];
        self::assertSame([['101', 'Cola', 2]], array_map(static fn (array $r): array => [$r['room']['number'], $r['items'][0]['name'], $r['items'][0]['quantity']], $refill['rooms']));
        self::assertSame([['Cola', 2]], array_map(static fn (array $t): array => [$t['name'], $t['quantity']], $refill['totals']));

        // The next check: two colas put back, and one nuts consumed.
        $this->check([['item_id' => $this->item['COLA'], 'consumed' => 0, 'refilled' => 2], ['item_id' => $this->item['NUTS'], 'consumed' => 1, 'refilled' => 1]]);
        self::assertSame([], $this->page()['refill']['rooms']);

        // The history is kept per room and per person, and nothing can be changed.
        $history = $this->page()['history']['checks'];
        self::assertSame(2, count($history));
        self::assertSame(['Budi Santoso', true], [$history[0]['guest_name'], $history[0]['posted']]);
        self::assertNotSame('', $history[0]['checked_by_name']);
        self::assertSame(2, count($this->page('?room='.self::ROOM)['history']['checks']));
        self::assertSame(0, count($this->page('?staff='.$this->manager->getKey())['history']['checks']));

        foreach ([fn () => DB::table('fnb_minibar_checks')->update(['consumed_minor' => 1]), fn () => DB::table('fnb_minibar_checks')->delete(), fn () => DB::table('fnb_minibar_check_lines')->update(['consumed' => 9]), fn () => DB::table('fnb_minibar_check_lines')->delete()] as $change) {
            try {
                $change();
                self::fail('a check cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_check_is_refused_when_it_cannot_have_happened(): void
    {
        $this->actAs($this->attendant);
        $this->check([], '101', 422);
        $this->check([['item_id' => $this->item['WATER'], 'consumed' => 0, 'refilled' => 0]], '101', 422);
        $this->check([['item_id' => $this->item['WATER'], 'consumed' => 3, 'refilled' => 0]], '101', 422);
        $this->check([['item_id' => $this->item['WATER'], 'consumed' => 1, 'refilled' => 0], ['item_id' => $this->item['WATER'], 'consumed' => 1, 'refilled' => 0]], '101', 422);
        $this->check([['item_id' => $this->item['WATER'], 'consumed' => 0, 'refilled' => 99]], '101', 422);
        $this->check([['item_id' => '01arz3ndektsv4rrffq69g5fc9', 'consumed' => 1, 'refilled' => 0]], '101', 422);
        $this->check([['item_id' => $this->item['WATER'], 'consumed' => 1, 'refilled' => 0]], '999', 404);
        self::assertSame(0, DB::table('fnb_minibar_checks')->count());
        self::assertSame([], $this->charged);

        $this->actAs($this->waiter);
        $this->check([['item_id' => $this->item['WATER'], 'consumed' => 1, 'refilled' => 0]], '101', 403);
        $this->get('/fnb/minibar/room?code=101')->assertForbidden();
    }

    public function test_nothing_is_charged_when_the_folio_is_closed_and_the_late_charge_procedure_is_pointed_to(): void
    {
        // Nobody is in the room any more.
        $this->app->instance(GuestCharging::class, new class implements GuestCharging
        {
            public function inHouseStayOfRoom(PropertyId $property, string $roomId): ?array
            {
                return null;
            }

            public function charge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array
            {
                throw new LogicException('a closed folio is never charged');
            }
        });
        $this->actAs($this->attendant);
        $scan = $this->page('?code=101')['scanned'];
        self::assertFalse($scan['in_house']);

        $this->postJson('/fnb/minibar/checks', ['room_code' => '101', 'lines' => [['item_id' => $this->item['WATER'], 'consumed' => 1, 'refilled' => 0]]], $this->key())->assertStatus(409)->assertJsonPath('error.message', fn (string $m): bool => true);
        self::assertSame(0, DB::table('fnb_minibar_checks')->count());
        self::assertSame([], DB::table('fnb_minibar_stock')->get()->all(), 'the room is not changed by a refused check');

        // What was put back can still be recorded.
        $check = $this->check([['item_id' => $this->item['WATER'], 'consumed' => 0, 'refilled' => 1]]);
        self::assertSame([0, false, null], [$check['consumed_minor'], $check['posted'], $check['guest_name']]);
    }

    public function test_a_room_service_order_has_a_room_a_time_promised_and_a_delivery_that_moves_on(): void
    {
        $this->actAs($this->manager);
        $outlet = (string) $this->postJson('/fnb/outlets', ['code' => 'RS', 'name' => 'Room service', 'kind' => 'room_service', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertCreated()->json('outlet.id');
        $this->actAs($this->waiter);
        $board = $this->get('/fnb/room-service')->assertOk()->viewData('page')['props']['board'];
        self::assertSame([['RS'], ['101']], [array_column($board['outlets'], 'code'), array_column($board['rooms'], 'number')]);

        $body = ['outlet_id' => $outlet, 'room_id' => self::ROOM, 'promised_time' => '12:00', 'covers' => 2, 'note' => 'No chili'];
        // 03:00 UTC is 10:00 in Jakarta.
        $this->postJson('/fnb/room-service', [...$body, 'promised_time' => '09:30'], $this->key())->assertStatus(422);
        $this->postJson('/fnb/room-service', [...$body, 'promised_time' => '9am'], $this->key())->assertStatus(422);
        $this->postJson('/fnb/room-service', [...$body, 'outlet_id' => $this->id['rest']], $this->key())->assertStatus(422);
        $this->postJson('/fnb/room-service', [...$body, 'room_id' => '01arz3ndektsv4rrffq69g5fc8'], $this->key())->assertStatus(422);
        $order = $this->postJson('/fnb/room-service', $body, $this->key())->assertCreated()->json();
        self::assertSame(['101', 'Budi Santoso', 'ordered', '2026-10-03T05:00:00Z', false], [$order['room']['number'], $order['guest_name'], $order['status'], $order['promised_at'], $order['late']]);
        $bill = DB::table('fnb_bills')->where('id', $order['bill_id'])->first();
        self::assertSame([self::ROOM, 'open', 2], [$bill->room_id, $bill->status, (int) $bill->covers]);

        // The delivery moves on one step at a time, and is late once the time passes.
        $this->postJson("/fnb/room-service/{$order['id']}/status", ['status' => 'delivered', 'lock_version' => 0])->assertStatus(409);
        $this->postJson("/fnb/room-service/{$order['id']}/status", ['status' => 'on_the_way', 'lock_version' => 9])->assertStatus(409);
        $this->postJson("/fnb/room-service/{$order['id']}/status", ['status' => 'on_the_way', 'lock_version' => 0])->assertOk()->assertJsonPath('status', 'on_the_way')->assertJsonPath('next', 'delivered');
        $this->clock->advance('+2 hours 20 minutes');
        $late = $this->get('/fnb/room-service')->viewData('page')['props']['board']['orders'][0];
        self::assertSame([true, 20], [$late['late'], $late['late_minutes']]);
        $this->postJson("/fnb/room-service/{$order['id']}/status", ['status' => 'delivered', 'lock_version' => 1])->assertOk()->assertJsonPath('status', 'delivered')->assertJsonPath('next', null)->assertJsonPath('late', false);
        $this->postJson("/fnb/room-service/{$order['id']}/status", ['status' => 'delivered', 'lock_version' => 2])->assertStatus(409);
        self::assertSame(['room_service.ordered', 'room_service.on_the_way', 'room_service.delivered'], DB::table('audit_entries')->where('aggregate_type', 'room_service_order')->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());

        try {
            DB::table('fnb_room_service_orders')->delete();
            self::fail('an order cannot be deleted');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->actAs($this->nobody);
        $this->get('/fnb/room-service')->assertForbidden();
    }
}
