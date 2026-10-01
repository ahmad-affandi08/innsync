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

        foreach (DB::table('hk_checklist_runs')->where('property_id', $property->toString())->where('name', $name)->where('period_key', $periodKey)->get(['id', 'target_ref', 'items']) as $run) {
            $result[$run->target_ref] = [
                'total' => count(json_decode((string) $run->items, true, 512, JSON_THROW_ON_ERROR)),
                'completed' => DB::table('hk_checklist_completions')->where('run_id', $run->id)->count(),
            ];
        }

        return $result;
    }

    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, string $actorId, DateTimeImmutable $at, string $businessDate): string
    {
        try {
            DB::table('hk_checklist_completions')->insert(['id' => $id, 'property_id' => $property->toString(), 'run_id' => $runId, 'item_id' => $itemId, 'note' => $note, 'completed_by' => $actorId, 'completed_at' => $at, 'business_date' => $businessDate]);
        } catch (UniqueConstraintViolationException) {
            return 'already';
        }

        return 'added';
    }

    public function completions(PropertyId $property, string $runId): array
    {
        return DB::table('hk_checklist_completions')->where('property_id', $property->toString())->where('run_id', $runId)->orderBy('completed_at')->get()
            ->map(static fn ($r): array => ['item_id' => $r->item_id, 'note' => $r->note, 'completed_by' => $r->completed_by, 'completed_at' => (new DateTimeImmutable((string) $r->completed_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')])->all();
    }

    public function performance(PropertyId $property, string $from, string $to): array
    {
        $runs = DB::table('hk_checklist_runs as r')->join('hk_checklist_templates as t', 't.id', '=', 'r.template_id')->where('r.property_id', $property->toString())
            ->where('r.period_end', '>=', $from)->where('r.period_start', '<=', $to)->orderBy('r.period_start')->orderBy('t.name')->orderBy('r.target_label')
            ->get(['r.id', 'r.period_key', 'r.items', 'r.target_label', 't.name', 't.frequency']);
        $result = [];

        foreach ($runs as $run) {
            $by = DB::table('hk_checklist_completions')->where('run_id', $run->id)->groupBy('completed_by')->pluck(DB::raw('COUNT(*)'), 'completed_by')->map(static fn ($n): int => (int) $n)->all();
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
