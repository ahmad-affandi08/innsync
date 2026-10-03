<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\DutyStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseDutyStore implements DutyStore
{
    public function addDuty(PropertyId $property, array $row, array $steps, DateTimeImmutable $at): void
    {
        DB::table('maintenance_duties')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
        $this->putSteps($row['id'], $steps);
    }

    public function duties(PropertyId $property, bool $onlyActive): array
    {
        $rows = DB::table('maintenance_duties as d')->leftJoin('maintenance_assets as a', 'a.id', '=', 'd.asset_id')->where('d.property_id', $property->toString())->when($onlyActive, static fn ($q) => $q->where('d.is_active', true))
            ->orderBy('d.title')->select('d.*', 'a.number as asset_number', 'a.name as asset_name')->get()->map(static fn ($r): array => (array) $r)->all();
        $steps = DB::table('maintenance_duty_steps')->whereIn('duty_id', array_column($rows, 'id'))->orderBy('position')->get()->groupBy('duty_id');

        return array_map(static fn (array $r): array => [...$r, 'steps' => ($steps[$r['id']] ?? collect())->pluck('text')->all()], $rows);
    }

    public function duty(PropertyId $property, string $id): ?array
    {
        $r = DB::table('maintenance_duties as d')->leftJoin('maintenance_assets as a', 'a.id', '=', 'd.asset_id')->where('d.property_id', $property->toString())->where('d.id', $id)->select('d.*', 'a.number as asset_number', 'a.name as asset_name')->first();

        return $r === null ? null : [...(array) $r, 'steps' => DB::table('maintenance_duty_steps')->where('duty_id', $id)->orderBy('position')->pluck('text')->all()];
    }

    public function updateDuty(PropertyId $property, string $id, int $lock, array $fields, ?array $steps, DateTimeImmutable $at): bool
    {
        $changed = DB::table('maintenance_duties')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;

        if ($changed && $steps !== null) {
            DB::table('maintenance_duty_steps')->where('duty_id', $id)->delete();
            $this->putSteps($id, $steps);
        }

        return $changed;
    }

    public function lastDue(PropertyId $property, string $dutyId): ?string
    {
        $d = DB::table('maintenance_duty_runs')->where('property_id', $property->toString())->where('duty_id', $dutyId)->max('due_on');

        return $d === null ? null : substr((string) $d, 0, 10);
    }

    public function addRun(PropertyId $property, array $row, array $steps, DateTimeImmutable $at): bool
    {
        try {
            DB::table('maintenance_duty_runs')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'open', 'lock_version' => 0, 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        foreach ($steps as $i => $text) {
            DB::table('maintenance_duty_run_steps')->insert(['id' => strtolower((string) Str::ulid()), 'run_id' => $row['id'], 'position' => $i + 1, 'text' => $text, 'result' => 'pending']);
        }

        return true;
    }

    public function runs(PropertyId $property, ?string $status, ?string $from, ?string $to, int $limit): array
    {
        return DB::table('maintenance_duty_runs as r')->where('r.property_id', $property->toString())
            ->when($status !== null, static fn ($q) => $q->where('r.status', $status))->when($from !== null, static fn ($q) => $q->where('r.due_on', '>=', $from))->when($to !== null, static fn ($q) => $q->where('r.due_on', '<=', $to))
            ->selectRaw('r.*, (SELECT COUNT(*) FROM maintenance_duty_run_steps s WHERE s.run_id = r.id) AS step_count, (SELECT COUNT(*) FROM maintenance_duty_run_steps s WHERE s.run_id = r.id AND s.result = \'pending\') AS pending_count, (SELECT COUNT(*) FROM maintenance_duty_run_steps s WHERE s.run_id = r.id AND s.result = \'issue\') AS issue_count')
            ->orderByDesc('r.due_on')->orderBy('r.title')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function run(PropertyId $property, string $id): ?array
    {
        $r = DB::table('maintenance_duty_runs')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : [...(array) $r, 'steps' => DB::table('maintenance_duty_run_steps')->where('run_id', $id)->orderBy('position')->get()->map(static fn ($s): array => (array) $s)->all()];
    }

    public function lockRun(PropertyId $property, string $id): void
    {
        DB::table('maintenance_duty_runs')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->value('id');
    }

    public function updateRun(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('maintenance_duty_runs')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function updateStep(PropertyId $property, string $runId, string $stepId, array $fields): bool
    {
        return DB::table('maintenance_duty_run_steps as s')->join('maintenance_duty_runs as r', 'r.id', '=', 's.run_id')->where('r.property_id', $property->toString())->where('s.run_id', $runId)->where('s.id', $stepId)->update(array_combine(array_map(static fn (string $k): string => "s.{$k}", array_keys($fields)), array_values($fields))) === 1;
    }

    public function markMissed(PropertyId $property, string $before, DateTimeImmutable $at): int
    {
        return DB::table('maintenance_duty_runs')->where('property_id', $property->toString())->where('status', 'open')->where('due_on', '<', $before)->update(['status' => 'missed', 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function propertiesWithDuties(): array
    {
        return DB::table('maintenance_duties')->where('is_active', true)->distinct()->pluck('property_id')->map(static fn ($p): string => (string) $p)->all();
    }

    /** @param list<string> $steps */
    private function putSteps(string $dutyId, array $steps): void
    {
        foreach ($steps as $i => $text) {
            DB::table('maintenance_duty_steps')->insert(['id' => strtolower((string) Str::ulid()), 'duty_id' => $dutyId, 'position' => $i + 1, 'text' => $text]);
        }
    }
}
