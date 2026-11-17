<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** DB-07: finished technical rows older than the window go, young ones and unfinished ones stay. */
final class PruneTechnicalTablesTest extends TestCase
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

    private function operation(string $id, ?string $completedAt): void
    {
        DB::table('idempotency_operations')->insert([
            'id' => $id, 'property_id' => self::A, 'actor_id' => null, 'operation' => 'test.prune', 'key_hash' => hash('sha256', $id), 'request_hash' => hash('sha256', 'x'), 'result_payload' => $completedAt === null ? null : 'x',
            'result_version' => 1, 'correlation_id' => '01arz3ndektsv4rrffq69g5fc1', 'last_replay_correlation_id' => null, 'replay_count' => 0, 'first_requested_at' => now('UTC')->subDays(200), 'completed_at' => $completedAt, 'last_replayed_at' => null,
        ]);
    }

    public function test_old_finished_idempotency_records_and_expired_cache_rows_go_and_the_rest_stay(): void
    {
        $this->createProperty(self::A, 'A');
        $this->operation('01arz3ndektsv4rrffq69g5fb1', now('UTC')->subDays(120)->toDateTimeString());
        $this->operation('01arz3ndektsv4rrffq69g5fb2', now('UTC')->subDays(10)->toDateTimeString());
        $this->operation('01arz3ndektsv4rrffq69g5fb3', null);
        DB::table('cache')->insert([['key' => 'old-prune', 'value' => 'x', 'expiration' => now()->subDay()->getTimestamp()], ['key' => 'live-prune', 'value' => 'x', 'expiration' => now()->addDay()->getTimestamp()]]);

        $this->artisan('maintenance:prune-technical')->assertSuccessful();

        self::assertSame(['01arz3ndektsv4rrffq69g5fb2', '01arz3ndektsv4rrffq69g5fb3'], DB::table('idempotency_operations')->orderBy('id')->pluck('id')->all());
        self::assertSame(['live-prune'], DB::table('cache')->whereIn('key', ['old-prune', 'live-prune'])->pluck('key')->all());
    }
}
