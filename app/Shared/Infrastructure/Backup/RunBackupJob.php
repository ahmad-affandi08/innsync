<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** A backup the owner asked for on the status screen. It runs on the same worker as the daily one; the result is written to the backup log and shown on the screen, success or failure. */
final class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct()
    {
        $this->onConnection((string) config('outbox.queue_connection'));
        $this->onQueue((string) config('outbox.queue_name'));
    }

    public function handle(BackupService $backup): void
    {
        try {
            $backup->run();
        } catch (BackupFailed) {
            // The service has already recorded the failure in the backup log.
        } catch (Throwable $e) {
            report($e);
        }
    }
}
