<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes what nobody reads again from the tables that only grow (DB-07): finished outbox messages with the receipts of their consumers, finished idempotency records, and expired cache rows.
 * Business records, the audit trail and anything a person can open are not touched. Rows go in small batches so the shared database is never held for long.
 */
final class PruneTechnicalTablesCommand extends Command
{
    private const BATCH = 2000;

    private const MAX_BATCHES = 50;

    protected $signature = 'maintenance:prune-technical {--days=90 : Keep finished rows this many days}';

    protected $description = 'Delete finished outbox messages, idempotency records and expired cache rows older than the retention window';

    public function handle(): int
    {
        $cutoff = now('UTC')->subDays(max(30, (int) $this->option('days')));
        $removed = [
            'processed_outbox_messages' => $this->batches(fn (): int => DB::table('processed_outbox_messages')->where('processed_at', '<', $cutoff)->limit(self::BATCH)->delete()),
            'outbox_messages' => $this->batches(fn (): int => DB::table('outbox_messages')->where('status', 'completed')->where('completed_at', '<', $cutoff)
                ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('processed_outbox_messages as p')->whereColumn('p.event_id', 'outbox_messages.id')->whereColumn('p.property_id', 'outbox_messages.property_id'))->limit(self::BATCH)->delete()),
            'idempotency_operations' => $this->batches(fn (): int => DB::table('idempotency_operations')->whereNotNull('completed_at')->where('completed_at', '<', $cutoff)->limit(self::BATCH)->delete()),
            'cache' => $this->batches(fn (): int => DB::table((string) config('cache.stores.database.table', 'cache'))->where('expiration', '<', now()->getTimestamp())->limit(self::BATCH)->delete()),
        ];

        foreach ($removed as $table => $n) {
            $this->line("{$table}: {$n}");
        }

        return self::SUCCESS;
    }

    private function batches(\Closure $delete): int
    {
        $total = 0;

        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $n = $delete();
            $total += $n;

            if ($n < self::BATCH) {
                break;
            }
        }

        return $total;
    }
}
