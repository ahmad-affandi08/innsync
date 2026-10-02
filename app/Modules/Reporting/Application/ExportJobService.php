<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\IssueSensitiveExport;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use Throwable;

/**
 * Report exports built in the background (FR-RPT-011). A person asks for an export of a report they may export; the request is
 * kept and a scheduled runner (every minute) builds it as that person, with the same checks, purpose and audit as a download made at
 * once, and keeps the result as a private file that belongs to the person and expires (the sensitive export retention, a day by
 * default). The person sees the status on a page and is told in the application when it is ready or has failed; no e-mail or
 * message is sent, because no mail provider is chosen. A request that was running when a worker stopped is taken up again.
 */
final readonly class ExportJobService
{
    /** @var array<string, list<string>> the reports that can be exported this way and the inputs each takes */
    public const REPORTS = [
        'movements' => ['date'], 'registrations' => ['preset', 'from', 'to', 'nationality'], 'foreign_guests' => ['preset', 'from', 'to'], 'payments' => ['preset', 'from', 'to'],
        'housekeeping' => ['preset', 'from', 'to'], 'laundry' => ['preset', 'from', 'to'], 'flash' => ['preset', 'from', 'to'], 'performance' => ['by', 'preset', 'from', 'to', 'year'], 'comparison' => ['kind'],
    ];

    public const MAX_BYTES = 52_428_800;

    /** A request running longer than this is taken to have been abandoned by a worker that stopped. */
    public const STALE_MINUTES = 30;

    public function __construct(
        private ExportJobRepository $jobs,
        private ReportService $reports,
        private IssueSensitiveExport $issue,
        private DownloadFile $downloadFile,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(PropertyId $property, string $actorId, string $report, array $params, ?string $purpose): array
    {
        $this->assertProperty($property);

        if (! isset(self::REPORTS[$report])) {
            throw Refusal::invalid('Choose a report that can be exported in the background.', ['report']);
        }

        if (! $this->reports->mayExport($property, $actorId, $report)) {
            throw Refusal::forbidden('This person may not export this report.');
        }

        $purpose = $purpose === null || trim($purpose) === '' ? null : trim($purpose);

        if (($purpose !== null && mb_strlen($purpose) > 300) || ($purpose === null && in_array($report, ReportService::PERSONAL_EXPORTS, true))) {
            throw Refusal::invalid('State why this is exported, at most 300 characters.', ['purpose']);
        }

        $clean = [];

        foreach (self::REPORTS[$report] as $key) {
            $value = $params[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value) && ! is_int($value) || (is_string($value) && mb_strlen($value) > 40)) {
                throw Refusal::invalid('An input of the report is not valid.', [$key]);
            }

            $clean[$key] = $value;
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $id, $report, $clean, $purpose, $actor): void {
            $this->jobs->add($property, $id, $report, $clean, $purpose, $actor, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'report.export_requested', 'report_export_job', $id, null, ['report' => $report, 'inputs' => array_keys($clean)], $purpose));
        });

        return $this->jobs->find($property, $id) ?? throw Refusal::notFound('Request not found.');
    }

    /** @return array{jobs: list<array<string, mixed>>, reports: list<string>, may: array<string, bool>, unseen: int} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);
        $actor = strtolower($actorId);
        $reports = array_values(array_filter(array_keys(self::REPORTS), fn (string $code): bool => $this->reports->mayExport($property, $actor, $code)));

        return [
            'jobs' => array_map(static fn (array $j): array => [...$j, 'file_id' => null, 'ready' => $j['status'] === 'done'], $this->jobs->ofPerson($property, $actor, 30)),
            'reports' => $reports, 'may' => array_fill_keys($reports, true), 'unseen' => $this->jobs->unseen($property, $actor),
        ];
    }

    /** How many finished exports the person has not looked at, for the notice on the reports page. */
    public function unseen(PropertyId $property, string $actorId): int
    {
        $this->assertProperty($property);

        return $this->jobs->unseen($property, strtolower($actorId));
    }

    public function markSeen(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);
        $this->jobs->markSeen($property, strtolower($actorId), $this->clock->nowUtc());
    }

    /** @return array{filename: string, file: FileContent} */
    public function download(PropertyId $property, string $actorId, string $jobId): array
    {
        $this->assertProperty($property);
        $job = $this->jobs->find($property, strtolower($jobId));

        // Someone else's export is not there for this person.
        if ($job === null || $job['requested_by'] !== strtolower($actorId)) {
            throw Refusal::notFound('Export not found.');
        }

        if ($job['status'] !== 'done' || $job['file_id'] === null) {
            throw Refusal::stateConflict('This export is not ready.');
        }

        $policy = new class implements FileAccessPolicy
        {
            public function allows(string $actorId, StoredFile $file): bool
            {
                return $file->ownerType === 'user' && $file->ownerId === $actorId;
            }
        };

        try {
            return ['filename' => (string) $job['filename'], 'file' => $this->downloadFile->execute($property, $job['file_id'], strtolower($actorId), $policy)];
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('This export has expired; ask for it again.');
        } catch (FileAccessDenied) {
            throw Refusal::notFound('Export not found.');
        }
    }

    /**
     * Builds the queued requests of the property, oldest first, up to `$max`. Called by the scheduled runner for each property.
     *
     * @return int how many were built or failed
     */
    public function runQueued(PropertyId $property, int $max = 5): int
    {
        $this->assertProperty($property);
        $this->jobs->requeueStale($property, $this->clock->nowUtc()->modify('-'.self::STALE_MINUTES.' minutes'));
        $done = 0;

        while ($done < $max && ($job = $this->jobs->claimNext($property, $this->clock->nowUtc())) !== null) {
            $this->build($property, $job);
            $done++;
        }

        return $done;
    }

    /** @param array<string, mixed> $job */
    private function build(PropertyId $property, array $job): void
    {
        $actor = $job['requested_by'];

        try {
            $result = $this->export($property, $actor, $job['report'], $job['params'], $job['purpose']);
            $file = $this->issue->execute($property, $actor, 'report.'.$job['report'], $result['contents'], ['text/csv', 'text/plain'], self::MAX_BYTES, $result['filename'], $job['purpose'] ?? 'Report export '.$job['report']);
            $rows = max(0, substr_count($result['contents'], "\n") - 1);
            $at = $this->clock->nowUtc();

            $this->transactions->run(function () use ($property, $job, $rows, $file, $result, $at, $actor): void {
                $this->jobs->finish($property, $job['id'], $rows, $file->id, $result['filename'], $at);
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'report.export_finished', 'report_export_job', $job['id'], null, ['report' => $job['report'], 'rows' => $rows, 'file_id' => $file->id]));
                $this->outbox->publish(new OutboxEvent($property, 'reporting.export.finished', $job['id'], 1, ['job_id' => $job['id'], 'report' => $job['report'], 'requested_by' => $actor, 'rows' => $rows]));
            });
        } catch (Throwable $e) {
            $message = $e instanceof Refusal ? $e->getMessage() : 'The export could not be built. Ask again, or tell support with this request.';

            if (! $e instanceof Refusal) {
                report($e);
            }

            $this->transactions->run(function () use ($property, $job, $message, $actor): void {
                $this->jobs->fail($property, $job['id'], $message, $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'report.export_failed', 'report_export_job', $job['id'], null, ['report' => $job['report']], mb_substr($message, 0, 300)));
                $this->outbox->publish(new OutboxEvent($property, 'reporting.export.failed', $job['id'], 1, ['job_id' => $job['id'], 'report' => $job['report'], 'requested_by' => $actor]));
            });
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{filename: string, contents: string}
     */
    private function export(PropertyId $property, string $actor, string $report, array $p, ?string $purpose): array
    {
        $s = static fn (string $k): ?string => isset($p[$k]) ? (string) $p[$k] : null;

        return match ($report) {
            'movements' => $this->reports->exportMovements($property, $actor, $s('date'), (string) $purpose),
            'registrations' => $this->reports->exportRegistrations($property, $actor, $s('preset'), $s('from'), $s('to'), $s('nationality'), false, (string) $purpose),
            'foreign_guests' => $this->reports->exportRegistrations($property, $actor, $s('preset'), $s('from'), $s('to'), null, true, (string) $purpose),
            'payments' => $this->reports->exportPayments($property, $actor, $s('preset'), $s('from'), $s('to')),
            'housekeeping' => $this->reports->exportHousekeeping($property, $actor, $s('preset'), $s('from'), $s('to')),
            'laundry' => $this->reports->exportLaundry($property, $actor, $s('preset'), $s('from'), $s('to')),
            'flash' => $this->reports->exportFlash($property, $actor, $s('preset'), $s('from'), $s('to')),
            'performance' => $this->reports->exportPerformance($property, $actor, $s('by') ?? 'day', $s('preset'), $s('from'), $s('to'), isset($p['year']) ? (int) $p['year'] : null),
            'comparison' => $this->reports->exportComparison($property, $actor, $s('kind') ?? 'day'),
            default => throw Refusal::invalid('This report cannot be exported in the background.', ['report']),
        };
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
