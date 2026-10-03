<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Offline\SyncExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** NFR-04: a manager reconciles what the server could not apply of the offline entries; nothing is dropped, and the person who made the entry does not close it themselves. */
final class SyncExceptionHttpTest extends TestCase
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

    private function exception(string $actor, string $operation = '01arz3ndektsv4rrffq69g5fo1'): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('offline_sync_exceptions')->insert([
            'id' => $id, 'property_id' => self::A, 'operation_id' => $operation, 'operation_type' => 'fnb.sale.record', 'device_id' => '01arz3ndektsv4rrffq69g5fd1', 'client_sequence' => 1, 'actor_id' => $actor,
            'kind' => 'conflict', 'reason_code' => 'stale_version', 'conflict_action' => 'review', 'payload_version' => 1, 'encrypted_payload' => 'secret-payload', 'device_time' => now(), 'received_at' => now(),
            'correlation_id' => '01arz3ndektsv4rrffq69g5fc1', 'status' => 'open', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_a_manager_reviews_and_reconciles_but_not_their_own_entry_and_not_twice(): void
    {
        $this->createProperty(self::A, 'A');
        $maker = UserRecord::factory()->create();
        $this->grant($maker, self::A, []);
        $id = $this->exception($maker->getKey());
        $mine = null;

        $this->signIn(self::A, []);
        $this->get('/sync/exceptions')->assertForbidden();

        $this->post('/logout');
        $manager = $this->signIn(self::A, [SyncExceptionService::RECONCILE_PERMISSION]);
        $mine = $this->exception(strtolower((string) $manager->getKey()), '01arz3ndektsv4rrffq69g5fo2');

        $page = $this->get('/sync/exceptions')->assertOk()->assertInertia(fn (Assert $p) => $p->component('foundation/pages/sync-exceptions')->has('overview.rows', 2)->where('overview.open', 2));
        self::assertStringNotContainsString('secret-payload', (string) $page->getContent());
        $this->get('/sync/exceptions?status=resolved')->assertInertia(fn (Assert $p) => $p->has('overview.rows', 0));
        $this->get('/sync/exceptions?status=weird')->assertStatus(422);

        $this->postJson("/sync/exceptions/{$mine}/resolve", ['lock_version' => 0, 'note' => 'Done'])->assertStatus(409);
        $this->postJson("/sync/exceptions/{$id}/resolve", ['lock_version' => 0, 'note' => ' '])->assertStatus(422);
        $this->postJson("/sync/exceptions/{$id}/resolve", ['lock_version' => 5, 'note' => 'Re-entered the sale'])->assertStatus(409);
        $this->postJson("/sync/exceptions/{$id}/resolve", ['lock_version' => 0, 'note' => 'Re-entered the sale'])->assertOk();
        $this->postJson("/sync/exceptions/{$id}/resolve", ['lock_version' => 1, 'note' => 'Again'])->assertStatus(409);

        self::assertSame('resolved', DB::table('offline_sync_exceptions')->where('id', $id)->value('status'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'offline.exception.resolved')->where('aggregate_id', $id)->count());
        $this->get('/sync/exceptions?status=resolved')->assertInertia(fn (Assert $p) => $p->has('overview.rows', 1)->where('overview.rows.0.resolution_note', 'Re-entered the sale'));
    }
}
