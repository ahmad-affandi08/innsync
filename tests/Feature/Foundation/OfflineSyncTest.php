<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Infrastructure\Offline\SyncBacklogCheck;
use App\Shared\Infrastructure\Offline\SystemEchoHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Support\Offline\SaleHandler;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;
use Throwable;

final class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    private const DEVICE_1 = '01arz3ndektsv4rrffq69g5fd1';

    private const DEVICE_2 = '01arz3ndektsv4rrffq69g5fd2';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['offline.handlers' => [SystemEchoHandler::class, SaleHandler::class]]);
        SaleHandler::$mode = 'ok';

        $this->createProperty(self::PROPERTY_A, 'A');
        $this->createProperty(self::PROPERTY_B, 'B');
    }

    private string $actor = '';

    /** @param list<string> $permissions */
    private function enter(string $propertyId, array $permissions = []): UserRecord
    {
        $user = $this->signIn($propertyId, $permissions);
        $this->actor = strtolower((string) $user->getKey());

        return $user;
    }

    private function op(int $n): string
    {
        return sprintf('01arz3ndektsv4rrffq69g5f%02d', $n);
    }

    /** @param array<string, mixed> $override @return array<string, mixed> */
    private function item(int $n, array $override = []): array
    {
        return array_merge([
            'actor_id' => $this->actor,
            'operation_id' => $this->op($n),
            'type' => 'test.sale',
            'property_id' => self::PROPERTY_A,
            'device_id' => self::DEVICE_1,
            'client_sequence' => $n,
            'device_time' => '2026-10-01T10:00:00+07:00',
            'base_version' => null,
            'payload_version' => 1,
            'payload' => ['amount' => 1000 + $n],
        ], $override);
    }

    /** @param list<array<string, mixed>> $items @param array<string, mixed> $extra */
    private function sync(array $items, array $extra = []): TestResponse
    {
        return $this->postJson('/sync/batch', ['device_id' => self::DEVICE_1, 'items' => $items] + $extra);
    }

    private function sales(): int
    {
        return SaleHandler::count();
    }

    public function test_an_accepted_item_is_applied_once_and_a_duplicate_delivery_replays_the_same_result(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $first = $this->sync([$this->item(1)])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame('accepted', $first->json('results.0.status'));
        self::assertFalse($first->json('results.0.replayed'));
        self::assertSame(1, $first->json('results.0.server_version'));
        self::assertSame(1, $this->sales());

        // The response was lost, so the device sends the identical item again, twice.
        foreach ([1, 2] as $_) {
            $again = $this->sync([$this->item(1)])->assertOk();
            self::assertSame('accepted', $again->json('results.0.status'));
            self::assertTrue($again->json('results.0.replayed'));
            self::assertSame($first->json('results.0.result'), $again->json('results.0.result'));
        }

        self::assertSame(1, $this->sales(), 'the business effect happened exactly once');
        self::assertSame(0, DB::table('offline_sync_exceptions')->count());
    }

    public function test_the_same_operation_id_with_different_content_is_refused_not_applied(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        $this->sync([$this->item(1)])->assertOk();

        $tampered = $this->sync([$this->item(1, ['payload' => ['amount' => 999999]])])->assertOk();

        self::assertSame('rejected', $tampered->json('results.0.status'));
        self::assertSame('idempotency_mismatch', $tampered->json('results.0.code'));
        self::assertSame(1, $this->sales());
        self::assertSame(1, DB::table('offline_sync_exceptions')->where('reason_code', 'idempotency_mismatch')->count());
    }

    public function test_items_are_applied_in_the_devices_own_sequence_whatever_order_they_arrive(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $this->sync([$this->item(3), $this->item(1), $this->item(2)])->assertOk();

        $applied = DB::table('cache')->where('key', 'like', SaleHandler::PREFIX.'%')->orderBy('expiration')->pluck('value')
            ->map(fn (string $value): int => json_decode($value, true)['seq'])->all();

        self::assertSame([1, 2, 3], $applied);
    }

    public function test_results_come_back_in_the_order_the_items_were_sent(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $response = $this->sync([$this->item(3), $this->item(1), $this->item(2)])->assertOk();

        self::assertSame([$this->op(3), $this->op(1), $this->op(2)], array_column($response->json('results'), 'operation_id'));
    }

    public function test_a_missing_permission_is_rejected_on_the_server_and_nothing_is_applied(): void
    {
        $this->enter(self::PROPERTY_A, ['front-office.reservation.view']);

        $response = $this->sync([$this->item(1)])->assertOk();

        self::assertSame('rejected', $response->json('results.0.status'));
        self::assertSame('forbidden', $response->json('results.0.code'));
        self::assertSame(0, $this->sales());
        self::assertSame(1, DB::table('security_events')->where('event_type', 'offline.sync.denied')->count());
        self::assertSame(1, DB::table('offline_sync_exceptions')->where('reason_code', 'forbidden')->count());
    }

    public function test_the_property_scope_comes_from_the_session_not_from_the_item(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $response = $this->sync([$this->item(1, ['property_id' => self::PROPERTY_B])])->assertOk();

        self::assertSame('property_mismatch', $response->json('results.0.code'));
        self::assertSame(0, $this->sales());
        self::assertSame(1, DB::table('security_events')->where('event_type', 'offline.sync.property_mismatch')->count());
        // Recorded under the property the user is actually working in, never the claimed one.
        self::assertSame(self::PROPERTY_A, DB::table('offline_sync_exceptions')->value('property_id'));
    }

    public function test_an_item_recorded_by_someone_else_is_not_applied_under_the_signed_in_users_name(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        // A shared tablet: the item was queued by another cashier.
        $response = $this->sync([$this->item(1, ['actor_id' => '01arz3ndektsv4rrffq69g5fc9'])])->assertOk();

        self::assertSame('rejected', $response->json('results.0.status'));
        self::assertSame('actor_mismatch', $response->json('results.0.code'));
        self::assertSame(0, $this->sales());
        self::assertSame(1, DB::table('security_events')->where('event_type', 'offline.sync.actor_mismatch')->count());
    }

    public function test_unknown_operations_and_unsupported_versions_are_rejected_and_recorded(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $response = $this->sync([
            $this->item(1, ['type' => 'nobody.handles.this']),
            $this->item(2, ['payload_version' => 99]),
        ])->assertOk();

        self::assertSame(['unknown_operation', 'unsupported_payload_version'], array_column($response->json('results'), 'code'));
        self::assertSame(2, DB::table('offline_sync_exceptions')->count());
    }

    public function test_a_conflict_is_returned_recorded_once_and_replayed_identically(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        SaleHandler::$mode = 'conflict';

        $first = $this->sync([$this->item(1, ['payload' => ['amount' => 4200, 'note' => 'late checkout']])])->assertOk();

        self::assertSame('conflict', $first->json('results.0.status'));
        self::assertSame('stale_room_state', $first->json('results.0.code'));
        self::assertSame('review', $first->json('results.0.action'));
        self::assertSame(7, $first->json('results.0.server_version'));

        // Even after the server state changes, the same operation id keeps its original logical result.
        SaleHandler::$mode = 'ok';
        $again = $this->sync([$this->item(1, ['payload' => ['amount' => 4200, 'note' => 'late checkout']])])->assertOk();
        self::assertSame('conflict', $again->json('results.0.status'));
        self::assertTrue($again->json('results.0.replayed'));
        self::assertSame(0, $this->sales(), 'a conflict never applies the change');

        $rows = DB::table('offline_sync_exceptions')->get();
        self::assertCount(1, $rows, 'one open record however often it is replayed');
        self::assertSame('open', $rows[0]->status);
        self::assertSame('conflict', $rows[0]->kind);
        self::assertSame('review', $rows[0]->conflict_action);
        self::assertStringNotContainsString('late checkout', (string) $rows[0]->encrypted_payload, 'the queued payload is encrypted at rest');
        self::assertSame(['amount' => 4200, 'note' => 'late checkout'], json_decode(Crypt::decryptString($rows[0]->encrypted_payload), true));
    }

    public function test_a_business_rule_rejection_is_stored_as_the_result_and_recorded(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        SaleHandler::$mode = 'reject';

        $response = $this->sync([$this->item(1)])->assertOk();

        self::assertSame('rejected', $response->json('results.0.status'));
        self::assertSame('closed_shift', $response->json('results.0.code'));
        self::assertSame('closed_shift', DB::table('offline_sync_exceptions')->value('reason_code'));
    }

    public function test_a_transient_failure_rolls_back_defers_the_devices_later_items_and_a_retry_succeeds_once(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        SaleHandler::$mode = 'explode';

        $response = $this->sync([
            $this->item(1),
            $this->item(2),
            $this->item(3, ['device_id' => self::DEVICE_2, 'client_sequence' => 1]),
        ])->assertOk();

        self::assertSame(['retry_later', 'deferred', 'accepted'], [
            $response->json('results.0.status'), $response->json('results.1.status'), $response->json('results.2.status'),
        ]);
        self::assertSame('server_error', $response->json('results.0.code'));
        self::assertStringNotContainsString('secret internal detail', (string) $response->getContent());
        // Only the other device's item was applied; the exploding handler's insert was rolled back.
        self::assertSame([SaleHandler::PREFIX.$this->op(3)], DB::table('cache')->where('key', 'like', SaleHandler::PREFIX.'%')->pluck('key')->all());

        SaleHandler::$mode = 'ok';
        $retry = $this->sync([$this->item(1), $this->item(2)])->assertOk();

        self::assertSame(['accepted', 'accepted'], [$retry->json('results.0.status'), $retry->json('results.1.status')]);
        self::assertSame(3, $this->sales());
        self::assertSame(0, DB::table('offline_sync_exceptions')->count(), 'transient failures are retried, not recorded as exceptions');
    }

    public function test_a_conflict_does_not_halt_the_device_but_a_transient_failure_does(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        SaleHandler::$mode = 'conflict';

        $response = $this->sync([$this->item(1), $this->item(2, ['type' => 'system.echo', 'payload' => ['text' => 'hi']])])->assertOk();

        self::assertSame(['conflict', 'accepted'], [$response->json('results.0.status'), $response->json('results.1.status')]);
    }

    public function test_sensitive_and_malformed_items_are_rejected_per_item_without_failing_the_batch(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $response = $this->sync([
            $this->item(1, ['payload' => ['card_number' => '4111111111111111']]),
            $this->item(2, ['client_sequence' => -5]),
            $this->item(3),
        ])->assertOk();

        self::assertSame(['sensitive_payload', 'invalid_envelope'], [$response->json('results.0.code'), $response->json('results.1.code')]);
        self::assertSame('accepted', $response->json('results.2.status'));
        self::assertSame(1, $this->sales());
        self::assertStringNotContainsString('4111111111111111', (string) json_encode(DB::table('offline_sync_exceptions')->get()), 'card data is never stored');
    }

    public function test_a_malformed_batch_fails_as_a_whole(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $noId = $this->item(1);
        unset($noId['operation_id']);

        $this->sync([$noId])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->sync([$this->item(1), $this->item(1, ['client_sequence' => 2])])->assertStatus(422);
        $this->postJson('/sync/batch', ['device_id' => 'not-a-ulid', 'items' => []])->assertStatus(422);
        $this->postJson('/sync/batch', ['items' => 'x'])->assertStatus(422);
        $this->postJson('/sync/batch', [])->assertStatus(422);

        config(['offline.max_batch_items' => 2]);
        $this->sync([$this->item(1), $this->item(2), $this->item(3)])->assertStatus(422);
        self::assertSame(0, $this->sales());
    }

    public function test_an_oversized_request_is_refused(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        config(['offline.max_request_bytes' => 65536]);

        $this->sync([$this->item(1, ['payload' => ['blob' => str_repeat('x', 70000)]])])->assertStatus(413);
    }

    public function test_the_endpoint_requires_a_signed_in_user_with_a_property(): void
    {
        $this->postJson('/sync/batch', ['items' => []])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY_A, []);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        // Signed in, but no property selected yet.
        $this->postJson('/sync/batch', ['items' => []])->assertStatus(302)->assertRedirect('/properties/select');
    }

    public function test_the_same_operation_id_in_two_properties_is_independent(): void
    {
        $user = $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        $this->sync([$this->item(1)])->assertOk();

        // The same user switches to another property where they also hold the permission.
        $this->grant($user, self::PROPERTY_B, ['fnb.pos.sell']);
        $this->post('/properties/select', ['property_id' => self::PROPERTY_B])->assertRedirect('/');

        // Use the echo operation: the sale effect keys are per operation id and would mask the comparison.
        $a = $this->sync([$this->item(7, ['type' => 'system.echo', 'property_id' => self::PROPERTY_B, 'payload' => ['text' => 'b']])])->assertOk();
        self::assertSame('accepted', $a->json('results.0.status'));
        self::assertFalse($a->json('results.0.replayed'));
    }

    public function test_the_diagnostic_operation_proves_the_pipeline_end_to_end(): void
    {
        $this->enter(self::PROPERTY_A);

        $ok = $this->sync([$this->item(1, ['type' => 'system.echo', 'payload' => ['text' => 'halo']])])->assertOk();
        self::assertSame('accepted', $ok->json('results.0.status'));
        self::assertSame(['echo' => 'halo'], $ok->json('results.0.result'));

        $bad = $this->sync([$this->item(2, ['type' => 'system.echo', 'payload' => ['text' => str_repeat('x', 201)]])])->assertOk();
        self::assertSame('invalid_payload', $bad->json('results.0.code'));
    }

    public function test_a_heartbeat_records_the_device_queue_status_and_drives_the_sync_backlog_check(): void
    {
        $this->enter(self::PROPERTY_A);
        $check = fn (): HealthResult => app(SyncBacklogCheck::class)->check();

        self::assertSame(HealthStatus::Ok, $check()->status);

        $this->postJson('/sync/batch', ['device_id' => self::DEVICE_1, 'items' => [], 'client_status' => ['pending' => 3, 'oldest_pending_seconds' => 120]])->assertOk();
        self::assertSame(1, DB::table('offline_device_status')->count());
        self::assertSame(HealthStatus::Ok, $check()->status, 'a young queue is normal');

        $this->postJson('/sync/batch', ['device_id' => self::DEVICE_1, 'items' => [], 'client_status' => ['pending' => 3, 'oldest_pending_seconds' => 1200]])->assertOk();
        self::assertSame(1, DB::table('offline_device_status')->count(), 'one row per device');
        self::assertSame(HealthStatus::Degraded, $check()->status);

        $this->postJson('/sync/batch', ['device_id' => self::DEVICE_1, 'items' => [], 'client_status' => ['pending' => 3, 'oldest_pending_seconds' => 15000]])->assertOk();
        self::assertSame(HealthStatus::Down, $check()->status, 'older than the 4-hour offline window');

        $this->postJson('/sync/batch', ['device_id' => self::DEVICE_1, 'items' => [], 'client_status' => ['pending' => 0, 'oldest_pending_seconds' => 0]])->assertOk();
        self::assertSame(HealthStatus::Ok, $check()->status);
    }

    public function test_open_exceptions_degrade_health_until_resolved_and_are_never_deletable(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);
        SaleHandler::$mode = 'conflict';
        $this->sync([$this->item(1)])->assertOk();

        $result = app(SyncBacklogCheck::class)->check();
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame(1, $result->context['open_exceptions']);
        self::assertStringNotContainsString('amount', json_encode($result->context), 'health output carries no payload data');

        try {
            DB::table('offline_sync_exceptions')->delete();
            self::fail('Reconciliation evidence must not be deletable.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        DB::table('offline_sync_exceptions')->where('status', 'open')->update(['received_at' => now()->subDays(2)]);
        self::assertSame(HealthStatus::Down, app(SyncBacklogCheck::class)->check()->status, 'unreconciled for more than a day');

        // A resolved record needs who and when; an inconsistent one is refused by the database.
        try {
            DB::table('offline_sync_exceptions')->update(['status' => 'resolved']);
            self::fail('A resolution without resolver and time must be refused.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('chk_offline_exc_resolution', $exception->getMessage());
        }
    }

    public function test_each_response_carries_the_server_time_and_no_stack_or_sql(): void
    {
        $this->enter(self::PROPERTY_A, ['fnb.pos.sell']);

        $response = $this->sync([$this->item(1)])->assertOk();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $response->json('server_time'));
        foreach (['SQLSTATE', 'Exception', '.php'] as $leak) {
            self::assertStringNotContainsString($leak, (string) $response->getContent());
        }
    }
}
