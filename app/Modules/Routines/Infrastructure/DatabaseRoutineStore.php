<?php

declare(strict_types=1);

namespace App\Modules\Routines\Infrastructure;

use App\Modules\Routines\Application\RoutineStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRoutineStore implements RoutineStore
{
    public function addTemplate(PropertyId $property, string $department, string $id, string $name, int $version, string $frequency, array $items, bool $active, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('routine_templates')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'department' => $department, 'name' => $name, 'version' => $version, 'frequency' => $frequency, 'items' => json_encode($items, JSON_THROW_ON_ERROR),
            'is_active' => $active, 'created_by' => $actorId, 'created_at' => $at->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function latestTemplates(PropertyId $property, string $department): array
    {
        $latest = DB::table('routine_templates')->where('property_id', $property->toString())->where('department', $department)->groupBy('name')->select('name', DB::raw('MAX(version) as v'));

        return DB::table('routine_templates as t')->joinSub($latest, 'l', static fn ($j) => $j->on('l.name', '=', 't.name')->on('l.v', '=', 't.version'))
            ->where('t.property_id', $property->toString())->where('t.department', $department)->orderBy('t.frequency')->orderBy('t.name')->get(['t.*'])->map(static fn ($r): array => self::shape($r))->all();
    }

    public function latestByName(PropertyId $property, string $department, string $name): ?array
    {
        $row = DB::table('routine_templates')->where('property_id', $property->toString())->where('department', $department)->where('name', $name)->orderByDesc('version')->first();

        return $row === null ? null : self::shape($row);
    }

    public function template(PropertyId $property, string $department, string $id): ?array
    {
        $row = DB::table('routine_templates')->where('property_id', $property->toString())->where('department', $department)->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function ensureRun(PropertyId $property, string $department, string $id, string $templateId, string $name, string $periodKey, string $start, string $end, array $items, DateTimeImmutable $at): array
    {
        try {
            DB::table('routine_runs')->insert(['id' => $id, 'property_id' => $property->toString(), 'department' => $department, 'template_id' => $templateId, 'name' => $name, 'period_key' => $periodKey, 'period_start' => $start, 'period_end' => $end, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => $at->format('Y-m-d H:i:s.u')]);
        } catch (UniqueConstraintViolationException) {
            // Someone began the same period first: use theirs.
        }

        return $this->findRun($property, $department, $name, $periodKey) ?? throw new \LogicException('The run must exist.');
    }

    public function findRun(PropertyId $property, string $department, string $name, string $periodKey): ?array
    {
        $row = DB::table('routine_runs')->where('property_id', $property->toString())->where('department', $department)->where('name', $name)->where('period_key', $periodKey)->first();

        return $row === null ? null : ['id' => $row->id, 'template_id' => $row->template_id, 'period_key' => $row->period_key, 'period_start' => substr((string) $row->period_start, 0, 10), 'period_end' => substr((string) $row->period_end, 0, 10), 'items' => json_decode((string) $row->items, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, string $actorId, DateTimeImmutable $at, string $businessDate): string
    {
        try {
            DB::table('routine_completions')->insert(['id' => $id, 'property_id' => $property->toString(), 'run_id' => $runId, 'item_id' => $itemId, 'note' => $note, 'completed_by' => $actorId, 'completed_at' => $at->format('Y-m-d H:i:s.u'), 'business_date' => $businessDate]);
        } catch (UniqueConstraintViolationException) {
            return 'already';
        }

        return 'added';
    }

    public function completions(PropertyId $property, string $runId): array
    {
        return DB::table('routine_completions')->where('property_id', $property->toString())->where('run_id', $runId)->orderBy('completed_at')->get()
            ->map(static fn ($r): array => ['item_id' => $r->item_id, 'note' => $r->note, 'completed_by' => $r->completed_by, 'completed_at' => (new DateTimeImmutable((string) $r->completed_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')])->all();
    }

    public function performance(PropertyId $property, string $department, string $from, string $to): array
    {
        $runs = DB::table('routine_runs as r')->join('routine_templates as t', 't.id', '=', 'r.template_id')->where('r.property_id', $property->toString())->where('r.department', $department)
            ->where('r.period_end', '>=', $from)->where('r.period_start', '<=', $to)->orderBy('r.period_start')->orderBy('t.name')->get(['r.id', 'r.period_key', 'r.items', 't.name', 't.frequency']);
        $result = [];

        foreach ($runs as $run) {
            $by = DB::table('routine_completions')->where('run_id', $run->id)->groupBy('completed_by')->pluck(DB::raw('COUNT(*)'), 'completed_by')->map(static fn ($n): int => (int) $n)->all();
            $result[] = ['run_id' => $run->id, 'template' => $run->name, 'frequency' => $run->frequency, 'period_key' => $run->period_key, 'items' => count(json_decode((string) $run->items, true, 512, JSON_THROW_ON_ERROR)), 'completed' => array_sum($by), 'by' => $by];
        }

        return $result;
    }

    public function addPoint(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('routine_temperature_points')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function points(PropertyId $property, string $department): array
    {
        return DB::table('routine_temperature_points')->where('property_id', $property->toString())->where('department', $department)->orderByDesc('is_active')->orderBy('name')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function point(PropertyId $property, string $department, string $id): ?array
    {
        $r = DB::table('routine_temperature_points')->where('property_id', $property->toString())->where('department', $department)->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function updatePoint(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('routine_temperature_points')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function addReading(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('routine_temperature_readings')->insert([...$row, 'property_id' => $property->toString(), 'recorded_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function readings(PropertyId $property, string $department, string $from, string $to, ?string $pointId, int $limit): array
    {
        return DB::table('routine_temperature_readings as r')->join('routine_temperature_points as p', 'p.id', '=', 'r.point_id')->where('r.property_id', $property->toString())->where('r.department', $department)
            ->whereBetween('r.business_date', [$from, $to])->when($pointId !== null, static fn ($q) => $q->where('r.point_id', $pointId))->orderByDesc('r.recorded_at')->orderByDesc('r.id')->limit($limit)->get(['r.*', 'p.name as point_name'])->map(static fn ($r): array => (array) $r)->all();
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'version' => (int) $r->version, 'frequency' => $r->frequency, 'items' => json_decode((string) $r->items, true, 512, JSON_THROW_ON_ERROR), 'is_active' => (bool) $r->is_active];
    }
}
