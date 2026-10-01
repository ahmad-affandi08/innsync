<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Routine;

use App\Modules\FrontOffice\Application\Routine\RoutineRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRoutineRepository implements RoutineRepository
{
    public function addTemplate(PropertyId $property, string $id, string $name, int $version, string $frequency, array $items, bool $active, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('sop_templates')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'name' => $name, 'version' => $version, 'frequency' => $frequency, 'items' => json_encode($items, JSON_THROW_ON_ERROR),
            'is_active' => $active, 'created_by' => $actorId, 'created_at' => $at,
        ]);
    }

    public function latestTemplates(PropertyId $property): array
    {
        $latest = DB::table('sop_templates')->where('property_id', $property->toString())->groupBy('name')->select('name', DB::raw('MAX(version) as v'));

        return DB::table('sop_templates as t')->joinSub($latest, 'l', static fn ($j) => $j->on('l.name', '=', 't.name')->on('l.v', '=', 't.version'))
            ->where('t.property_id', $property->toString())->orderBy('t.frequency')->orderBy('t.name')->get(['t.*'])->map(static fn ($r): array => self::shape($r))->all();
    }

    public function latestByName(PropertyId $property, string $name): ?array
    {
        $row = DB::table('sop_templates')->where('property_id', $property->toString())->where('name', $name)->orderByDesc('version')->first();

        return $row === null ? null : self::shape($row);
    }

    public function template(PropertyId $property, string $id): ?array
    {
        $row = DB::table('sop_templates')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function ensureRun(PropertyId $property, string $id, string $templateId, string $name, string $periodKey, string $start, string $end, array $items, DateTimeImmutable $at): array
    {
        try {
            DB::table('sop_runs')->insert(['id' => $id, 'property_id' => $property->toString(), 'template_id' => $templateId, 'name' => $name, 'period_key' => $periodKey, 'period_start' => $start, 'period_end' => $end, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            // Someone else began the same period first: use theirs.
        }

        return $this->findRun($property, $name, $periodKey) ?? throw new \LogicException('The run must exist.');
    }

    public function findRun(PropertyId $property, string $name, string $periodKey): ?array
    {
        $row = DB::table('sop_runs')->where('property_id', $property->toString())->where('name', $name)->where('period_key', $periodKey)->first();

        return $row === null ? null : ['id' => $row->id, 'template_id' => $row->template_id, 'period_key' => $row->period_key, 'period_start' => substr((string) $row->period_start, 0, 10), 'period_end' => substr((string) $row->period_end, 0, 10), 'items' => json_decode((string) $row->items, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function complete(PropertyId $property, string $id, string $runId, string $itemId, ?string $note, string $actorId, DateTimeImmutable $at, string $businessDate): string
    {
        try {
            DB::table('sop_completions')->insert(['id' => $id, 'property_id' => $property->toString(), 'run_id' => $runId, 'item_id' => $itemId, 'note' => $note, 'completed_by' => $actorId, 'completed_at' => $at, 'business_date' => $businessDate]);
        } catch (UniqueConstraintViolationException) {
            return 'already';
        }

        return 'added';
    }

    public function completions(PropertyId $property, string $runId): array
    {
        return DB::table('sop_completions')->where('property_id', $property->toString())->where('run_id', $runId)->orderBy('completed_at')->get()
            ->map(static fn ($r): array => ['item_id' => $r->item_id, 'note' => $r->note, 'completed_by' => $r->completed_by, 'completed_at' => (new DateTimeImmutable((string) $r->completed_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')])->all();
    }

    public function performance(PropertyId $property, string $from, string $to): array
    {
        $runs = DB::table('sop_runs as r')->join('sop_templates as t', 't.id', '=', 'r.template_id')->where('r.property_id', $property->toString())
            ->where('r.period_end', '>=', $from)->where('r.period_start', '<=', $to)->orderBy('r.period_start')->orderBy('t.name')->get(['r.id', 'r.period_key', 'r.items', 't.name', 't.frequency']);
        $result = [];

        foreach ($runs as $run) {
            $by = DB::table('sop_completions')->where('run_id', $run->id)->groupBy('completed_by')->pluck(DB::raw('COUNT(*)'), 'completed_by')->map(static fn ($n): int => (int) $n)->all();
            $result[] = [
                'run_id' => $run->id, 'template' => $run->name, 'frequency' => $run->frequency, 'period_key' => $run->period_key,
                'items' => count(json_decode((string) $run->items, true, 512, JSON_THROW_ON_ERROR)), 'completed' => array_sum($by), 'by' => $by,
            ];
        }

        return $result;
    }

    public function addEntry(PropertyId $property, string $id, string $businessDate, string $shift, string $priority, string $body, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('shift_log_entries')->insert(['id' => $id, 'property_id' => $property->toString(), 'business_date' => $businessDate, 'shift' => $shift, 'priority' => $priority, 'body' => $body, 'author_id' => $actorId, 'created_at' => $at]);
    }

    public function entries(PropertyId $property, string $readerId, string $sinceUtc, int $limit): array
    {
        return DB::table('shift_log_entries as e')->leftJoin('shift_log_reads as r', static fn ($j) => $j->on('r.entry_id', '=', 'e.id')->where('r.user_id', '=', $readerId))
            ->where('e.property_id', $property->toString())->where('e.created_at', '>=', $sinceUtc)->orderByDesc('e.created_at')->orderByDesc('e.id')->limit($limit)
            ->get(['e.*', 'r.read_at'])->map(static fn ($e): array => [
                'id' => $e->id, 'business_date' => substr((string) $e->business_date, 0, 10), 'shift' => $e->shift, 'priority' => $e->priority, 'body' => $e->body, 'author_id' => $e->author_id,
                'created_at' => (new DateTimeImmutable((string) $e->created_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'read' => $e->read_at !== null || $e->author_id === $readerId,
            ])->all();
    }

    public function markRead(PropertyId $property, string $readerId, array $entryIds, DateTimeImmutable $at): int
    {
        $added = 0;

        foreach ($entryIds as $entryId) {
            $exists = DB::table('shift_log_entries')->where('property_id', $property->toString())->where('id', $entryId)->exists();

            if (! $exists) {
                continue;
            }

            try {
                DB::table('shift_log_reads')->insert(['entry_id' => $entryId, 'user_id' => $readerId, 'property_id' => $property->toString(), 'read_at' => $at]);
                $added++;
            } catch (UniqueConstraintViolationException) {
                // already read
            }
        }

        return $added;
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'version' => (int) $r->version, 'frequency' => $r->frequency, 'items' => json_decode((string) $r->items, true, 512, JSON_THROW_ON_ERROR), 'is_active' => (bool) $r->is_active];
    }
}
