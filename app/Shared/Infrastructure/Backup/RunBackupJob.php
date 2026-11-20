<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** A backup the owner asked for on the status screen. It has its own worker (see routes/console.php); the result is written to the backup log and shown on the screen, success or failure. */
final class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct()
    {
        // Its own queue and connection: a backup takes minutes, and on the outbox worker it would be handed out again after 90 seconds and hold up every message behind it.
        $this->onConnection('database_long');
        $this->onQueue('backup');
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
