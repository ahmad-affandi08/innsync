<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\PerformanceStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabasePerformanceStore implements PerformanceStore
{
    public function noteRun(PropertyId $property, string $id, string $source, string $runId, string $checklist, string $frequency, string $periodKey, string $periodStart, int $total, int $completed, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        $percent = $total === 0 ? 0 : min(100, intdiv($completed * 100, $total));
        $key = ['property_id' => $property->toString(), 'source' => $source, 'run_id' => $runId];

        if (DB::table('hr_sop_runs')->where($key)->exists()) {
            DB::table('hr_sop_runs')->where($key)->where('completed', '<', $completed)->update(['completed' => $completed, 'total' => $total, 'percent' => $percent, 'updated_at' => $stamp]);

            return;
        }

        try {
            DB::table('hr_sop_runs')->insert([...$key, 'id' => $id, 'checklist' => $checklist, 'frequency' => $frequency, 'period_key' => $periodKey, 'period_start' => $periodStart, 'total' => $total, 'completed' => $completed, 'percent' => $percent, 'updated_at' => $stamp]);
        } catch (UniqueConstraintViolationException) {
            // Another delivery of the same run was first.
        }
    }

    public function credit(PropertyId $property, string $id, string $source, string $runId, string $itemKey, string $userId, int $weight, string $day, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_sop_credits')->insert(['id' => $id, 'property_id' => $property->toString(), 'source' => $source, 'run_id' => $runId, 'item_key' => $itemKey, 'user_id' => $userId, 'weight' => $weight, 'credited_on' => $day, 'occurred_at' => $at->format('Y-m-d H:i:s.u')]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function runs(PropertyId $property, string $from, string $to): array
    {
        return DB::table('hr_sop_runs')->where('property_id', $property->toString())->whereBetween('period_start', [$from, $to])->orderBy('source')->orderBy('period_start')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function creditsBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('hr_sop_credits')->where('property_id', $property->toString())->whereBetween('credited_on', [$from, $to])->groupBy('user_id', 'source')->selectRaw('user_id, source, SUM(weight) as credits')->get()
            ->map(static fn ($r): array => ['user_id' => (string) $r->user_id, 'source' => (string) $r->source, 'credits' => (int) $r->credits])->all();
    }
}
