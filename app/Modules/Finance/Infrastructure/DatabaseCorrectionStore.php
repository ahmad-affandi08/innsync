<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\CorrectionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCorrectionStore implements CorrectionStore
{
    public function corrections(PropertyId $property, ?string $status, ?string $dayDate): array
    {
        $lines = DB::table('fin_correction_lines')->groupBy('correction_id')->selectRaw("correction_id, COUNT(*) as line_count, SUM(CASE WHEN kind = 'revenue' THEN total_minor ELSE 0 END) as revenue, SUM(received_minor) as received");

        return DB::table('fin_corrections as c')->leftJoinSub($lines, 'l', 'l.correction_id', '=', 'c.id')->where('c.property_id', $property->toString())
            ->when($status !== null, static fn ($q) => $q->where('c.status', $status))->when($dayDate !== null, static fn ($q) => $q->where('c.business_date', $dayDate))
            ->orderByRaw("c.status = 'pending' DESC")->orderByDesc('c.number')
            ->get(['c.*', DB::raw('COALESCE(l.line_count, 0) as line_count'), DB::raw('COALESCE(l.revenue, 0) as revenue_minor'), DB::raw('COALESCE(l.received, 0) as received_minor')])
            ->map(static fn ($r): array => (array) $r)->all();
    }

    public function correction(PropertyId $property, string $id): ?array
    {
        $row = DB::table('fin_corrections')->where('property_id', $property->toString())->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        $row['lines'] = DB::table('fin_correction_lines')->where('correction_id', $id)->orderBy('kind', 'desc')->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all();

        return $row;
    }

    public function byNumber(PropertyId $property, string $number): ?array
    {
        $row = DB::table('fin_corrections')->where('property_id', $property->toString())->where('number', $number)->first();

        return $row === null ? null : (array) $row;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('fin_corrections')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fin_corrections')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'pending', 'lock_version' => 0, 'created_at' => $at]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }

        foreach ($lines as $line) {
            DB::table('fin_correction_lines')->insert([...$line, 'property_id' => $property->toString(), 'correction_id' => $row['id']]);
        }

        return true;
    }

    public function decide(PropertyId $property, string $id, int $lock, string $status, string $by, ?string $note, ?string $effectiveDate, DateTimeImmutable $at): bool
    {
        return DB::table('fin_corrections')->where('property_id', $property->toString())->where('id', $id)->where('status', 'pending')->where('lock_version', $lock)
            ->update(['status' => $status, 'decided_by' => $by, 'decided_at' => $at, 'decision_note' => $note, 'effective_date' => $effectiveDate, 'lock_version' => $lock + 1]) === 1;
    }

    public function approvedTotals(PropertyId $property, string $from, string $to): array
    {
        $base = fn () => DB::table('fin_correction_lines as l')->join('fin_corrections as c', 'c.id', '=', 'l.correction_id')->where('c.property_id', $property->toString())->where('c.status', 'approved')->whereBetween('c.effective_date', [$from, $to]);
        $revenue = $base()->where('l.kind', 'revenue')->groupBy('l.outlet_code', 'l.outlet_name')->orderBy('l.outlet_code')
            ->get(['l.outlet_code', 'l.outlet_name', DB::raw('SUM(l.base_minor) as base'), DB::raw('SUM(l.service_charge_minor) as service'), DB::raw('SUM(l.tax_minor) as tax'), DB::raw('SUM(l.total_minor) as total')])
            ->map(static fn ($r): array => ['outlet_code' => (string) $r->outlet_code, 'outlet_name' => $r->outlet_name === null ? null : (string) $r->outlet_name, 'base_minor' => (int) $r->base, 'service_charge_minor' => (int) $r->service, 'tax_minor' => (int) $r->tax, 'total_minor' => (int) $r->total])->all();
        $payments = $base()->where('l.kind', 'payment')->groupBy('l.method')->orderBy('l.method')->get(['l.method', DB::raw('SUM(l.received_minor) as received')])
            ->map(static fn ($r): array => ['method' => (string) $r->method, 'received_minor' => (int) $r->received])->all();

        return ['revenue' => $revenue, 'payments' => $payments, 'count' => (int) DB::table('fin_corrections')->where('property_id', $property->toString())->where('status', 'approved')->whereBetween('effective_date', [$from, $to])->count()];
    }
}
