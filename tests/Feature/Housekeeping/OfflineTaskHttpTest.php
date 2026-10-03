<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** NFR-04: an attendant starts and finishes rooms with no network; the steps are kept on the phone and applied once, in order, when it is back, and a task that changed meanwhile is a conflict, not an overwrite. */
final class OfflineTaskHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const DEVICE = '01arz3ndektsv4rrffq69g5fd2';

    private string $roomId;

    private string $taskId;

    private UserRecord $attendant;

    private UserRecord $supervisor;

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
        $this->attendant = UserRecord::factory()->create(['name' => 'Rina Attendant']);
        $this->grant($this->attendant, self::A, [HousekeepingService::PERFORM_PERMISSION]);
        $this->supervisor = $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, HousekeepingService::MANAGE_PERMISSION, HousekeepingService::INSPECT_PERMISSION,
            HousekeepingService::SETTINGS_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->roomId = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-02', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'hk-off-key-000001'])->assertCreated()->json('reservation.id');
        $stay = $this->postJson("/front-office/reservations/{$reservation}/check-in", [
            'room_id' => $this->roomId, 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'hk-off-ci-00000001'])->assertCreated()->json('stay');
        $this->postJson("/front-office/stays/{$stay['id']}/check-out", ['lock_version' => $stay['lock_version']])->assertOk();
        $this->taskId = (string) DB::table('housekeeping_tasks')->value('id');
        $this->postJson("/housekeeping/tasks/{$this->taskId}/assign", ['assigned_to' => strtolower((string) $this->attendant->getKey()), 'lock_version' => 0])->assertOk();
    }

    private function as(UserRecord $user): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, mixed> */
    private function step(int $n, string $step, ?int $base, UserRecord $user, string $task = ''): array
    {
        return [
            'actor_id' => strtolower((string) $user->getKey()), 'operation_id' => sprintf('01arz3ndektsv4rrffq69g5f%02d', $n), 'type' => 'hk.task.progress', 'property_id' => self::A, 'device_id' => self::DEVICE, 'client_sequence' => $n,
            'device_time' => '2026-10-01T10:00:00+07:00', 'base_version' => $base, 'payload_version' => 1, 'payload' => ['task_id' => $task === '' ? $this->taskId : $task, 'step' => $step],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function sync(array $items): TestResponse
    {
        return $this->postJson('/sync/batch', ['device_id' => self::DEVICE, 'items' => $items])->assertOk();
    }

    public function test_the_steps_made_offline_are_applied_once_in_order_however_often_they_are_sent(): void
    {
        $this->as($this->attendant);
        $steps = [$this->step(1, 'start', 1, $this->attendant), $this->step(2, 'finish', 2, $this->attendant)];

        $first = $this->sync($steps);
        self::assertSame(['accepted', 'accepted'], [$first->json('results.0.status'), $first->json('results.1.status')]);
        self::assertSame('in_progress', $first->json('results.0.result.status'));
        self::assertSame('done', $first->json('results.1.result.status'));
        self::assertSame('clean', DB::table('housekeeping_rooms')->value('status'));
        self::assertSame('done', DB::table('housekeeping_tasks')->value('status'));
        $audits = DB::table('audit_entries')->count();

        $again = $this->sync($steps);
        self::assertSame(['accepted', 'accepted'], [$again->json('results.0.status'), $again->json('results.1.status')]);
        self::assertSame($audits, DB::table('audit_entries')->count(), 'a retry applies nothing twice');
        self::assertSame(1, DB::table('housekeeping_tasks')->where('status', 'done')->count());
    }

    public function test_a_task_that_changed_meanwhile_is_a_conflict_and_the_room_is_left_alone(): void
    {
        // The supervisor took the task back while the phone had no network.
        $this->postJson("/housekeeping/tasks/{$this->taskId}/assign", ['assigned_to' => strtolower((string) $this->attendant->getKey()), 'lock_version' => 1])->assertOk();
        $this->as($this->attendant);

        $result = $this->sync([$this->step(3, 'start', 1, $this->attendant)]);
        self::assertSame(['conflict', 'task_changed'], [$result->json('results.0.status'), $result->json('results.0.code')]);
        self::assertSame('dirty', DB::table('housekeeping_rooms')->value('status'));
        self::assertSame(1, DB::table('offline_sync_exceptions')->count(), 'a person can reconcile it');
    }

    public function test_a_guest_who_asked_not_to_be_disturbed_stops_a_step_made_offline(): void
    {
        // The front desk takes a do-not-disturb for a room only while a guest is in it; here the guest asked just before leaving, so the flag is written as it would stand.
        DB::table('room_service_flags')->insert(['id' => '01arz3ndektsv4rrffq69g5fe1', 'property_id' => self::A, 'room_id' => $this->roomId, 'kind' => 'dnd', 'note' => 'Sleeping', 'started_at' => now(), 'started_by' => strtolower((string) $this->supervisor->getKey()), 'ended_at' => null, 'ended_by' => null, 'lock_version' => 0]);
        $this->as($this->attendant);

        $result = $this->sync([$this->step(4, 'start', 1, $this->attendant)]);
        self::assertSame(['conflict', 'guest_flag'], [$result->json('results.0.status'), $result->json('results.0.code')]);
        self::assertSame('assigned', DB::table('housekeeping_tasks')->value('status'));
    }

    public function test_the_right_to_clean_and_a_well_formed_step_are_needed(): void
    {
        $this->as($this->attendant);
        $bad = $this->sync([$this->step(5, 'inspect', 1, $this->attendant), $this->step(6, 'start', null, $this->attendant), [...$this->step(7, 'start', 1, $this->attendant), 'payload' => ['task_id' => 'nope', 'step' => 'start']]]);
        self::assertSame([['rejected', 'invalid_payload'], ['rejected', 'invalid_payload'], ['rejected', 'invalid_payload']], array_map(fn (int $i): array => [$bad->json("results.{$i}.status"), $bad->json("results.{$i}.code")], [0, 1, 2]));

        $this->as($this->supervisor);
        $denied = $this->sync([$this->step(8, 'start', 1, $this->supervisor)]);
        self::assertSame('rejected', $denied->json('results.0.status'));
        self::assertSame('assigned', DB::table('housekeeping_tasks')->value('status'));
    }
}
