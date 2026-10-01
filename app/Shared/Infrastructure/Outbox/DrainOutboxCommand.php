<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\DrainOutbox;
use Illuminate\Console\Command;

final class DrainOutboxCommand extends Command
{
    protected $signature = 'outbox:drain {--limit= : Maximum messages to enqueue}';

    protected $description = 'Atomically move due outbox messages to the database queue';

    public function handle(DrainOutbox $drain): int
    {
        $configuredLimit = (int) config('outbox.drain_batch_size');
        $option = $this->option('limit');
        $limit = $option === null ? $configuredLimit : filter_var($option, FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->components->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $count = $drain->execute($limit);
        $this->components->info(sprintf('Queued %d outbox message(s).', $count));

        return self::SUCCESS;
    }
}
