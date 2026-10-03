<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ReportScheduleRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseReportScheduleRepository implements ReportScheduleRepository
{
    public function all(PropertyId $property): array
    {
        $rows = DB::table('report_schedules')->where('property_id', $property->toString())->orderByDesc('is_active')->orderBy('name')->get()->map(static fn (object $r): array => (array) $r)->all();

        return $this->withRecipients($rows);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('report_schedules')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : $this->withRecipients([(array) $row])[0];
    }

    public function add(PropertyId $property, array $row, array $recipients, DateTimeImmutable $at): void
    {
        DB::table('report_schedules')->insert([...$row, 'params' => json_encode($row['params'], JSON_THROW_ON_ERROR), 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        $this->setRecipients((string) $row['id'], $recipients);
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, ?array $recipients, DateTimeImmutable $at): bool
    {
        if (isset($fields['params'])) {
            $fields['params'] = json_encode($fields['params'], JSON_THROW_ON_ERROR);
        }

        $changed = DB::table('report_schedules')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;

        if ($changed && $recipients !== null) {
            $this->setRecipients($id, $recipients);
        }

        return $changed;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('report_schedules')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function due(PropertyId $property, DateTimeImmutable $now, int $limit): array
    {
        $rows = DB::table('report_schedules')->where('property_id', $property->toString())->where('is_active', true)->where('next_run_at', '<=', $now)->orderBy('next_run_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();

        return $this->withRecipients($rows);
    }

    public function ran(PropertyId $property, string $id, DateTimeImmutable $ranAt, DateTimeImmutable $next): void
    {
        DB::table('report_schedules')->where('property_id', $property->toString())->where('id', $id)->update(['last_run_at' => $ranAt, 'next_run_at' => $next]);
    }

    public function addRun(PropertyId $property, array $row): void
    {
        DB::table('report_schedule_runs')->insert([...$row, 'property_id' => $property->toString()]);
    }

    public function runs(PropertyId $property, int $perSchedule): array
    {
        $rows = DB::table('report_schedule_runs')->where('property_id', $property->toString())->orderByDesc('ran_at')->limit(500)->get()->map(static fn (object $r): array => (array) $r)->all();
        $count = [];
        $out = [];

        foreach ($rows as $r) {
            $count[$r['schedule_id']] = ($count[$r['schedule_id']] ?? 0) + 1;

            if ($count[$r['schedule_id']] <= $perSchedule) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** @param list<string> $recipients */
    private function setRecipients(string $scheduleId, array $recipients): void
    {
        DB::table('report_schedule_recipients')->where('schedule_id', $scheduleId)->delete();

        foreach (array_values(array_unique($recipients)) as $userId) {
            DB::table('report_schedule_recipients')->insert(['schedule_id' => $scheduleId, 'user_id' => $userId]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withRecipients(array $rows): array
    {
        $recipients = DB::table('report_schedule_recipients')->whereIn('schedule_id', array_column($rows, 'id'))->orderBy('user_id')->get()->groupBy('schedule_id');

        foreach ($rows as &$r) {
            $r['params'] = json_decode((string) $r['params'], true, 512, JSON_THROW_ON_ERROR);
            $r['recipients'] = array_values(array_map(static fn (object $x): string => strtolower((string) $x->user_id), $recipients->get($r['id'])?->all() ?? []));
        }

        return $rows;
    }
}
