<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\DoorLocks\DoorLockJobConsumer;
use App\Modules\FrontOffice\Application\DoorLocks\DoorLockService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Door locks that follow the stay: the key jobs the stay events make, and the program beside the lock system that fetches them with a token and reports back. */
final class DoorLockHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    /** @var list<string> */
    private array $permissions = [];

    private string $type = '';

    private string $plan = '';

    /** @var array<string, string> */
    private array $room = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity_access.login_rate_limit_per_minute' => 1000]);

        $this->createProperty(self::A, 'A');
        $this->permissions = [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION,
            ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, DoorLockService::MANAGE_PERMISSION,
        ];
        $this->signIn(self::A, $this->permissions);
        $this->type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');

        foreach (['101', '102'] as $number) {
            $this->room[$number] = (string) $this->postJson('/property/rooms', ['number' => $number, 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated()->json('room.id');
        }

        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->plan}/prices", ['room_type_id' => $this->type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function work(): void
    {
        // The drain compares `available_at` with the clock to the second: a message made in this very second is taken at the next tick.
        // Two rounds: a message that was not yet due in the first (the clock of the database and the application can differ by a moment under load) is taken in the second.
        // A queue worker forgets the scoped instances; the routes already used keep their controllers, so the context they hold is put back.
        $context = app(PropertyContext::class);

        foreach ([1, 2] as $round) {
            sleep(1);
            $this->artisan('outbox:drain')->assertSuccessful();
            $this->artisan('queue:work', ['connection' => (string) config('outbox.queue_connection'), '--queue' => (string) config('outbox.queue_name'), '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096])->run();
        }

        $this->app->instance(PropertyContext::class, $context);
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, $this->permissions);
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_the_stay_makes_key_jobs_and_the_bridge_fetches_and_reports_them_with_its_token(): void
    {
        $this->putJson('/front-office/door-locks', ['enabled' => true, 'vendor' => 'salto', 'key_count' => 2, 'lock_version' => null])->assertStatus(409);
        $token = (string) $this->postJson('/front-office/door-locks/token', [])->assertCreated()->json('token');
        $this->putJson('/front-office/door-locks', ['enabled' => true, 'vendor' => 'made-up', 'key_count' => 2, 'lock_version' => 0])->assertStatus(422);
        $this->putJson('/front-office/door-locks', ['enabled' => true, 'vendor' => 'salto', 'key_count' => 7, 'lock_version' => 0])->assertStatus(422);
        $this->putJson('/front-office/door-locks', ['enabled' => true, 'vendor' => 'salto', 'key_count' => 2, 'lock_version' => 9])->assertStatus(409);
        $this->putJson('/front-office/door-locks', ['enabled' => true, 'vendor' => 'salto', 'key_count' => 2, 'lock_version' => 0])->assertOk();
        self::assertStringNotContainsString(substr($token, 27), (string) json_encode(DB::table('audit_entries')->where('action', 'like', 'door_lock.%')->get()), 'the audit never holds the token');
        self::assertNotSame($token, (string) DB::table('door_lock_settings')->value('token_hash'));

        $reservation = $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'door-lock-res-0000001'])->assertCreated()->json('reservation.id');
        $stay = $this->postJson("/front-office/reservations/{$reservation}/check-in", ['room_id' => $this->room['101'], 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0], ['Idempotency-Key' => 'door-lock-ci-00000001'])->assertCreated()->json('stay');
        $moved = $this->postJson("/front-office/stays/{$stay['id']}/move", ['room_id' => $this->room['102'], 'reason' => 'Noise', 'lock_version' => $stay['lock_version']])->assertOk()->json('stay');
        $this->postJson("/front-office/stays/{$stay['id']}/extend", ['departure' => '2026-10-05', 'reason' => 'Likes it', 'lock_version' => $moved['lock_version']])->assertOk();
        $this->work();

        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $ids = app(IdentifierGenerator::class);
        app(DoorLockJobConsumer::class)->consume(new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), 'frontoffice.stay.checked_out', $stay['id'], 1, ['stay_id' => $stay['id'], 'reservation_id' => $reservation, 'room_id' => $this->room['102']]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));

        $jobs = DB::table('door_lock_jobs')->get()->map(fn ($j): string => $j->kind.' '.$j->room_number.' '.($j->valid_until === null ? '-' : substr((string) $j->valid_until, 0, 10)))->sort()->values()->all();
        self::assertSame(['issue 101 2026-10-03', 'issue 102 2026-10-05', 'revoke 101 -', 'revoke 102 -', 'update 102 2026-10-05'], $jobs, 'keys made, moved with the room, given a new last day, taken back');
        self::assertNotNull(DB::table('door_lock_jobs')->where('kind', 'issue')->value('reservation_number'));
        self::assertSame(2, (int) DB::table('door_lock_jobs')->value('key_count'));

        // Once more, the same events: nothing new is made.
        $this->artisan('outbox:drain')->assertSuccessful();
        self::assertSame(5, DB::table('door_lock_jobs')->count());

        $this->getJson('/api/bridge/door-lock/jobs')->assertStatus(401);
        $this->getJson('/api/bridge/door-lock/jobs', $this->bearer('01arz3ndektsv4rrffq69g5fav.'.str_repeat('a', 48)))->assertStatus(401);
        $this->getJson('/api/bridge/door-lock/jobs', $this->bearer('nonsense'))->assertStatus(401);

        $pulled = $this->getJson('/api/bridge/door-lock/jobs?limit=3', $this->bearer($token))->assertOk()->json('jobs');
        self::assertCount(3, $pulled);
        self::assertSame(['id', 'kind', 'room', 'reservation', 'keys', 'valid_until'], array_keys($pulled[0]), 'nothing about the guest is sent');
        self::assertStringNotContainsString('Budi', (string) json_encode($pulled));
        self::assertSame(3, DB::table('door_lock_jobs')->where('status', 'sent')->count());

        // The program beside the lock system has no session: no property is in force until the token says which.
        app(PropertyContext::class)->clear();
        $this->postJson("/api/bridge/door-lock/jobs/{$pulled[0]['id']}/result", ['outcome' => 'done'], $this->bearer($token))->assertOk();
        $this->postJson("/api/bridge/door-lock/jobs/{$pulled[0]['id']}/result", ['outcome' => 'done'], $this->bearer($token))->assertOk();
        $this->postJson("/api/bridge/door-lock/jobs/{$pulled[1]['id']}/result", ['outcome' => 'failed', 'message' => 'Encoder offline'], $this->bearer($token))->assertOk();
        $this->postJson("/api/bridge/door-lock/jobs/{$pulled[2]['id']}/result", ['outcome' => 'maybe'], $this->bearer($token))->assertStatus(422);
        $this->postJson('/api/bridge/door-lock/jobs/01arz3ndektsv4rrffq69g5fav/result', ['outcome' => 'done'], $this->bearer($token))->assertNotFound();
        self::assertSame(['done' => 1, 'failed' => 1, 'pending' => 2, 'sent' => 1], DB::table('door_lock_jobs')->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all());

        // The people who run the hotel see it all, try the failed one again, and settle one by hand.
        $this->get('/front-office/door-locks')->assertOk()->assertInertia(fn ($p) => $p->component('front-office/pages/door-locks')->has('overview.jobs', 5)->where('overview.counts.failed', 1)->where('overview.settings.vendor', 'salto'));
        $this->postJson("/front-office/door-locks/jobs/{$pulled[1]['id']}/retry", [])->assertOk();
        $this->postJson("/front-office/door-locks/jobs/{$pulled[0]['id']}/retry", [])->assertStatus(409);
        $this->postJson("/front-office/door-locks/jobs/{$pulled[2]['id']}/settle", ['reason' => ' '])->assertStatus(422);
        $this->postJson("/front-office/door-locks/jobs/{$pulled[2]['id']}/settle", ['reason' => 'Made at the lock station'])->assertOk();
        self::assertSame('manual', DB::table('door_lock_jobs')->where('id', $pulled[2]['id'])->value('status'));
        $this->postJson("/api/bridge/door-lock/jobs/{$pulled[2]['id']}/result", ['outcome' => 'failed'], $this->bearer($token))->assertOk();
        self::assertSame('manual', DB::table('door_lock_jobs')->where('id', $pulled[2]['id'])->value('status'), 'a settled job is not changed by a late report');

        $again = $this->getJson('/api/bridge/door-lock/jobs', $this->bearer($token))->assertOk()->json('jobs');
        self::assertContains($pulled[1]['id'], array_column($again, 'id'), 'the job tried again comes back');

        // A new token stops the old one; switching door locks off stops the bridge.
        $new = (string) $this->postJson('/front-office/door-locks/token', [])->assertCreated()->json('token');
        $this->getJson('/api/bridge/door-lock/jobs', $this->bearer($token))->assertStatus(401);
        $this->getJson('/api/bridge/door-lock/jobs', $this->bearer($new))->assertOk();
        $this->putJson('/front-office/door-locks', ['enabled' => false, 'vendor' => 'salto', 'key_count' => 2, 'lock_version' => 1])->assertOk();
        $this->getJson('/api/bridge/door-lock/jobs', $this->bearer($new))->assertStatus(401);
    }

    public function test_nothing_is_made_while_door_locks_are_off_and_only_people_who_set_them_up_see_them(): void
    {
        $reservation = $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'door-lock-res-0000002'])->assertCreated()->json('reservation.id');
        $this->postJson("/front-office/reservations/{$reservation}/check-in", ['room_id' => $this->room['101'], 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0], ['Idempotency-Key' => 'door-lock-ci-00000002'])->assertCreated();
        $this->work();
        self::assertSame(0, DB::table('door_lock_jobs')->count());

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION]);
        $this->get('/front-office/door-locks')->assertForbidden();
        $this->postJson('/front-office/door-locks/token', [])->assertForbidden();
    }
}
