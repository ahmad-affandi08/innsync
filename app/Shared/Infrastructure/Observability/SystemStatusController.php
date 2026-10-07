<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Application\Observability\Health\RunHealthChecks;
use App\Shared\Infrastructure\Backup\BackupRunLog;
use App\Shared\Application\Messaging\MessagingSettings;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Is the system healthy, and is it being backed up?" for the owner, on one screen (owner request 2026-10-07). It reads what the health monitor and the backup log already
 * record; it changes nothing. A person who is not technical sees at a glance whether something needs attention, and the checks say what.
 */
final readonly class SystemStatusController
{
    public function __construct(private RunHealthChecks $checks, private BackupRunLog $backups, private MessagingSettings $messaging) {}

    public function show(): Response
    {
        $report = $this->checks->execute()->toArray();
        $mailer = (string) config('mail.default');

        return Inertia::render('foundation/pages/system-status', [
            'status' => $report['status'],
            'checks' => array_map(static fn (string $name, array $c): array => ['name' => $name, 'status' => $c['status'], 'summary' => $c['summary']], array_keys($report['checks']), array_values($report['checks'])),
            'backup' => ['last' => $this->backups->lastFinished('backup'), 'verify' => $this->backups->lastFinished('verify')],
            'environment' => [
                'version' => (string) config('app.version'),
                // What a person can fix without a developer: mail that goes nowhere, debug left on in production.
                'mail_delivers' => ($this->messaging->get('email')?->enabled ?? false) || ! in_array($mailer, ['log', 'array', 'null'], true),
                'debug_off' => ! (bool) config('app.debug'),
                'production' => app()->environment('production'),
            ],
        ]);
    }
}
