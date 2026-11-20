<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Infrastructure;

use App\Modules\Housekeeping\Application\ChecklistRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseChecklistRepository implements ChecklistRepository
{
    public function addTemplate(PropertyId $property, string $id, string $name, int $version, string $frequency, string $scope, array $areas, array $items, bool $active, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('hk_checklist_templates')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'name' => $name, 'version' => $version, 'frequency' => $frequency, 'scope' => $scope,
            'areas' => json_encode($areas, JSON_THROW_ON_ERROR), 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'is_active' => $active, 'created_by' => $actorId, 'created_at' => $at,
        ]);
    }

    public function latestTemplates(PropertyId $property): array
    {
        $latest = DB::table('hk_checklist_templates')->where('property_id', $property->toString())->groupBy('name')->select('name', DB::raw('MAX(version) as v'));

        return DB::table('hk_checklist_templates as t')->joinSub($latest, 'l', static fn ($j) => $j->on('l.name', '=', 't.name')->on('l.v', '=', 't.version'))
            ->where('t.property_id', $property->toString())->orderBy('t.frequency')->orderBy('t.name')->get(['t.*'])->map(static fn ($r): array => self::shape($r))->all();
    }

    public function latestByName(PropertyId $property, string $name): ?array
    {
        $row = DB::table('hk_checklist_templates')->where('property_id', $property->toString())->where('name', $name)->orderByDesc('version')->first();

        return $row === null ? null : self::shape($row);
    }

    public function template(PropertyId $property, string $id): ?array
    {
        $row = DB::table('hk_checklist_templates')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function ensureRun(PropertyId $property, string $id, string $templateId, string $name, string $periodKey, string $start, string $end, string $targetRef, string $targetLabel, array $items, DateTimeImmutable $at): array
    {
        try {
            DB::table('hk_checklist_runs')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'template_id' => $templateId, 'name' => $name, 'period_key' => $periodKey, 'period_start' => $start, 'period_end' => $end,
                'target_ref' => $targetRef, 'target_label' => $targetLabel, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Someone else began the same one first: use theirs.
        }

        return $this->findRun($property, $name, $periodKey, $targetRef) ?? throw new \LogicException('The run must exist.');
    }

    public function findRun(PropertyId $property, string $name, string $periodKey, string $targetRef): ?array
    {
        $row = DB::table('hk_checklist_runs')->where('property_id', $property->toString())->where('name', $name)->where('period_key', $periodKey)->where('target_ref', $targetRef)->first();

        return $row === null ? null : ['id' => $row->id, 'template_id' => $row->template_id, 'target_ref' => $row->target_ref, 'target_label' => $row->target_label, 'items' => json_decode((string) $row->items, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function progress(PropertyId $property, string $name, string $periodKey): array
    {
        $result = [];
        $runs = DB::table('hk_checklist_runs')->where('property_id', $property->toString())->where('name', $name)->where('period_key', $periodKey);
        $done = DB::table('hk_checklist_completions as c')->joinSub((clone $runs)->select('id'), 'r', 'r.id', '=', 'c.run_id')->groupBy('c.run_id')->pluck(DB::raw('COUNT(*)'), 'c.run_id');

        foreach ($runs->get(['id', 'target_ref', 'items']) as $run) {
            $result[$run->target_ref] = [
                'total' => count(json_decode((string) $run->items, true, 512, JSON_THROW_ON_ERROR)),
                'completed' => (int) ($done[$run->id] ?? 0),
            ];
        }

        return $result;
    }

    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, ?string $photoFileId, string $actorId, DateTimeImmutable $at, string $businessDate): string
    {
        try {
            DB::table('hk_checklist_completions')->insert(['id' => $id, 'property_id' => $property->toString(), 'run_id' => $runId, 'item_id' => $itemId, 'note' => $note, 'photo_file_id' => $photoFileId, 'completed_by' => $actorId, 'completed_at' => $at, 'business_date' => $businessDate]);
        } catch (UniqueConstraintViolationException) {
            return 'already';
        }

        return 'added';
    }

    public function completions(PropertyId $property, string $runId): array
    {
        return DB::table('hk_checklist_completions')->where('property_id', $property->toString())->where('run_id', $runId)->orderBy('completed_at')->get()
            ->map(static fn ($r): array => ['id' => $r->id, 'item_id' => $r->item_id, 'note' => $r->note, 'photo_file_id' => $r->photo_file_id, 'completed_by' => $r->completed_by, 'completed_at' => (new DateTimeImmutable((string) $r->completed_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')])->all();
    }

    public function findCompletion(PropertyId $property, string $completionId): ?array
    {
        $row = DB::table('hk_checklist_completions')->where('property_id', $property->toString())->where('id', $completionId)->first();

        return $row === null ? null : ['photo_file_id' => $row->photo_file_id];
    }

    public function performance(PropertyId $property, string $from, string $to): array
    {
        $runs = DB::table('hk_checklist_runs as r')->join('hk_checklist_templates as t', 't.id', '=', 'r.template_id')->where('r.property_id', $property->toString())
            ->where('r.period_end', '>=', $from)->where('r.period_start', '<=', $to)->orderBy('r.period_start')->orderBy('t.name')->orderBy('r.target_label')
            ->get(['r.id', 'r.period_key', 'r.items', 'r.target_label', 't.name', 't.frequency']);
        $result = [];
        $byRun = [];

        // One grouped query for every run of the period, not one per run.
        foreach (DB::table('hk_checklist_completions as c')->join('hk_checklist_runs as r', 'r.id', '=', 'c.run_id')->where('r.property_id', $property->toString())
            ->where('r.period_end', '>=', $from)->where('r.period_start', '<=', $to)->groupBy('c.run_id', 'c.completed_by')->get(['c.run_id', 'c.completed_by', DB::raw('COUNT(*) as n')]) as $row) {
            $byRun[$row->run_id][$row->completed_by] = (int) $row->n;
        }

        foreach ($runs as $run) {
            $by = $byRun[$run->id] ?? [];
            $result[] = [
                'run_id' => $run->id, 'template' => $run->name, 'frequency' => $run->frequency, 'period_key' => $run->period_key, 'target' => $run->target_label,
                'items' => count(json_decode((string) $run->items, true, 512, JSON_THROW_ON_ERROR)), 'completed' => array_sum($by), 'by' => $by,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'version' => (int) $r->version, 'frequency' => $r->frequency, 'scope' => $r->scope,
            'areas' => json_decode((string) $r->areas, true, 512, JSON_THROW_ON_ERROR), 'items' => json_decode((string) $r->items, true, 512, JSON_THROW_ON_ERROR), 'is_active' => (bool) $r->is_active,
        ];
    }
}
