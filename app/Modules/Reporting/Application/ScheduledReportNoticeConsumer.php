<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffContacts;

/**
 * Tells the recipient of a scheduled report by e-mail that it is ready, or that it could not be built, when the schedule asks for the notice. The message carries no figures: it names the schedule
 * and points at the exports page, where the file waits behind the person's own sign-in. A person with no e-mail address, or a mailer that fails, never makes the message wait: the report is
 * on the page either way.
 */
final readonly class ScheduledReportNoticeConsumer implements OutboxConsumer
{
    public function __construct(private ExportJobRepository $jobs, private ReportScheduleRepository $schedules, private StaffContacts $contacts, private ReportNotifier $notifier) {}

    public function name(): string
    {
        return 'reporting.scheduled-report-notice';
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, ['reporting.export.finished', 'reporting.export.failed'], true);
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $job = $this->jobs->find($event->propertyId, strtolower((string) ($event->data['job_id'] ?? '')));

        if ($job === null || ($job['schedule_id'] ?? null) === null) {
            return;
        }

        $schedule = $this->schedules->find($event->propertyId, (string) $job['schedule_id']);

        if ($schedule === null || ! (bool) $schedule['notify_email']) {
            return;
        }

        $address = $this->contacts->emailsOf($event->propertyId, [(string) $job['requested_by']])[(string) $job['requested_by']] ?? null;

        if ($address === null) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/').'/reports/exports';
        $ready = $event->eventType === 'reporting.export.finished';
        $name = (string) $schedule['name'];

        $this->notifier->notify(
            $address,
            $ready ? "Laporan terjadwal siap / Scheduled report ready: {$name}" : "Laporan terjadwal gagal / Scheduled report failed: {$name}",
            $ready
                ? "Laporan terjadwal \"{$name}\" sudah siap. Buka {$url} setelah masuk untuk mengunduhnya.\n\nThe scheduled report \"{$name}\" is ready. Sign in and open {$url} to download it.\n\nPesan ini tidak memuat data laporan. / This message carries no report data."
                : "Laporan terjadwal \"{$name}\" tidak dapat dibuat. Lihat {$url} setelah masuk.\n\nThe scheduled report \"{$name}\" could not be built. Sign in and see {$url}.",
        );
    }
}
