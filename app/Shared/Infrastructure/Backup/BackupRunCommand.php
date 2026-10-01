<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Illuminate\Console\Command;

final class BackupRunCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Create an encrypted full backup (database + private files) at BACKUP_PATH';

    public function handle(BackupService $backup): int
    {
        try {
            $result = $backup->run();
        } catch (BackupFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->components->error('Backup failed unexpectedly; see the backup_runs table.');
            report($e);

            return self::FAILURE;
        }

        $this->components->info(sprintf('Backup %s created (%d bytes encrypted).', $result['set'], $result['size_bytes']));

        return self::SUCCESS;
    }
}
