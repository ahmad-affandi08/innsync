<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ExportJobRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseExportJobRepository implements ExportJobRepository
{
    public function add(PropertyId $property, string $id, string $report, array $params, ?string $purpose, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('report_export_jobs')->insert(['id' => $id, 'property_id' => $property->toString(), 'report' => $report, 'params' => json_encode($params, JSON_THROW_ON_ERROR), 'purpose' => $purpose, 'requested_by' => $actorId, 'status' => 'queued', 'requested_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('report_export_jobs')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function ofPerson(PropertyId $property, string $actorId, int $limit): array
    {
        return DB::table('report_export_jobs')->where('property_id', $property->toString())->where('requested_by', $actorId)->orderByDesc('requested_at')->orderByDesc('id')->limit($limit)->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function claimNext(PropertyId $property, DateTimeImmutable $at): ?array
    {
        return DB::transaction(function () use ($property, $at): ?array {
            $row = DB::table('report_export_jobs')->where('property_id', $property->toString())->where('status', 'queued')->orderBy('requested_at')->orderBy('id')->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            DB::table('report_export_jobs')->where('id', $row->id)->update(['status' => 'running', 'started_at' => $at]);

            return self::shape($row);
        });
    }

    public function finish(PropertyId $property, string $id, int $rows, string $fileId, string $filename, DateTimeImmutable $at): void
    {
        DB::table('report_export_jobs')->where('property_id', $property->toString())->where('id', $id)->where('status', 'running')->update(['status' => 'done', 'row_count' => $rows, 'file_id' => $fileId, 'filename' => $filename, 'finished_at' => $at]);
    }

    public function fail(PropertyId $property, string $id, string $error, DateTimeImmutable $at): void
    {
        DB::table('report_export_jobs')->where('property_id', $property->toString())->where('id', $id)->where('status', 'running')->update(['status' => 'failed', 'error' => mb_substr($error, 0, 300), 'finished_at' => $at]);
    }

    public function unseen(PropertyId $property, string $actorId): int
    {
        return DB::table('report_export_jobs')->where('property_id', $property->toString())->where('requested_by', $actorId)->whereIn('status', ['done', 'failed'])->whereNull('seen_at')->count();
    }

    public function markSeen(PropertyId $property, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('report_export_jobs')->where('property_id', $property->toString())->where('requested_by', $actorId)->whereIn('status', ['done', 'failed'])->whereNull('seen_at')->update(['seen_at' => $at]);
    }

    public function requeueStale(PropertyId $property, DateTimeImmutable $before): int
    {
        return DB::table('report_export_jobs')->where('property_id', $property->toString())->where('status', 'running')->where('started_at', '<', $before->format('Y-m-d H:i:s.u'))->update(['status' => 'queued', 'started_at' => null]);
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        $utc = static fn (?string $v): ?string => $v === null ? null : substr($v, 0, 19).'Z';

        return [
            'id' => $r->id, 'report' => $r->report, 'params' => json_decode((string) $r->params, true, 512, JSON_THROW_ON_ERROR), 'purpose' => $r->purpose, 'requested_by' => $r->requested_by, 'status' => $r->status,
            'rows' => $r->row_count === null ? null : (int) $r->row_count, 'file_id' => $r->file_id, 'filename' => $r->filename, 'error' => $r->error,
            'requested_at' => $utc($r->requested_at), 'started_at' => $utc($r->started_at), 'finished_at' => $utc($r->finished_at), 'seen' => $r->seen_at !== null,
        ];
    }
}
