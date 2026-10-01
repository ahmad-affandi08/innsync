<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;

/** NFR-20 "backup failure" signal plus proof that restores are actually being tested. */
final readonly class BackupCheck implements HealthCheck
{
    public function __construct(private BackupRunLog $log, private Clock $clock) {}

    public function name(): string
    {
        return 'backup';
    }

    public function check(): HealthResult
    {
        if (! BackupSettings::isConfigured()) {
            $summary = 'Backups are not configured (BACKUP_PATH / BACKUP_ENCRYPTION_KEY).';

            return app()->environment('production') ? HealthResult::down($summary) : HealthResult::degraded($summary);
        }

        $now = $this->clock->nowUtc()->getTimestamp();
        $last = $this->log->lastSuccess('backup');
        $age = $last === null ? null : max(0, intdiv($now - $last->getTimestamp(), 3600));
        $context = ['last_backup_age_hours' => $age];

        if ($age === null || $age >= (int) config('backup.max_age_hours_down')) {
            return HealthResult::down('No recent successful backup exists.', $context);
        }

        if ($age >= (int) config('backup.max_age_hours_degraded')) {
            return HealthResult::degraded('The latest successful backup is overdue.', $context);
        }

        if (($this->log->lastFinished('backup')['status'] ?? null) === 'failed') {
            return HealthResult::degraded('The most recent backup run failed.', $context);
        }

        $test = $this->log->lastSuccess('restore_test');
        $testAge = $test === null ? null : intdiv($now - $test->getTimestamp(), 86400);
        $context['last_restore_test_age_days'] = $testAge;

        if ($testAge === null || $testAge >= (int) config('backup.restore_test_max_age_days')) {
            return HealthResult::degraded('No recent successful restore test exists.', $context);
        }

        return HealthResult::ok('Backups and restore tests are current.', $context);
    }
}
