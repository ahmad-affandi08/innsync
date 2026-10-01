<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Retention;

use App\Shared\Application\Retention\RetentionPurger;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Daily, from the scheduler. One property failing must not stop the others; any failure makes the exit code non-zero. */
final class RetentionPurgeCommand extends Command
{
    protected $signature = 'retention:purge';

    protected $description = 'Erase stored files whose retention period has ended, unless a legal hold applies';

    public function handle(RetentionPurger $purger): int
    {
        $limit = max(1, (int) config('retention.purge_batch_size'));
        $failed = false;
        $erased = 0;
        $held = 0;

        foreach (DB::table('properties')->orderBy('id')->pluck('id') as $id) {
            try {
                $result = $purger->purge(PropertyId::fromString((string) $id), $limit);
                $erased += $result['erased'];
                $held += $result['held'];
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: retention purge failed.");
            }
        }

        $this->line(json_encode(['erased' => $erased, 'held' => $held, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
