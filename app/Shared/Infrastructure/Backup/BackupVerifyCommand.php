<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Illuminate\Console\Command;

final class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {--set= : Backup set name; defaults to the latest}';

    protected $description = 'Restore a backup into the scratch database and verify it (restore test)';

    public function handle(RestoreVerifier $verifier): int
    {
        try {
            $result = $verifier->run($this->option('set') ?: null);
        } catch (BackupFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->components->error('Restore test failed unexpectedly; see the backup_runs table.');
            report($e);

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Restore test of %s passed in %d ms.',
            $result['set'],
            $result['restore_duration_ms'],
        ));

        return self::SUCCESS;
    }
}
