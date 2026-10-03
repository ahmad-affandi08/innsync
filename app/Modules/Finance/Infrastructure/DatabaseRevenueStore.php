<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\RevenueStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRevenueStore implements RevenueStore
{
    public function outletsBySource(PropertyId $property): array
    {
        $out = [];

        foreach (DB::table('revenue_outlet_sources as s')->join('revenue_outlets as o', 'o.id', '=', 's.outlet_id')->where('s.property_id', $property->toString())->get(['s.source', 'o.code', 'o.name']) as $row) {
            $out[(string) $row->source] = ['code' => (string) $row->code, 'name' => (string) $row->name];
        }

        return $out;
    }

    public function addDay(PropertyId $property, array $day, array $lines, array $payments, DateTimeImmutable $at): bool
    {
        $pid = $property->toString();

        if (! $this->insert('fin_revenue_days', [...$day, 'property_id' => $pid, 'status' => 'recorded', 'created_at' => $at])) {
            return false;
        }

        foreach ($lines as $line) {
            DB::table('fin_revenue_lines')->insert([...$line, 'property_id' => $pid]);
        }

        foreach ($payments as $payment) {
            DB::table('fin_payment_lines')->insert([...$payment, 'property_id' => $pid]);
        }

        return true;
    }

    public function days(PropertyId $property, string $from, string $to): array
    {
        return $this->rows(DB::table('fin_revenue_days')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->orderBy('business_date')->get());
    }

    public function day(PropertyId $property, string $date): ?array
    {
        $day = $this->row(DB::table('fin_revenue_days')->where('property_id', $property->toString())->where('business_date', $date)->first());

        if ($day === null) {
            return null;
        }

        $day['lines'] = $this->rows(DB::table('fin_revenue_lines')->where('day_id', $day['id'])->orderBy('source')->get());
        $day['payments'] = $this->rows(DB::table('fin_payment_lines')->where('day_id', $day['id'])->orderBy('method')->get());

        return $day;
    }

    public function lockDay(PropertyId $property, string $id): void
    {
        DB::table('fin_revenue_days')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function verifyDay(PropertyId $property, string $id, string $by, ?string $note, DateTimeImmutable $at): void
    {
        DB::table('fin_revenue_days')->where('property_id', $property->toString())->where('id', $id)->update(['status' => 'verified', 'verified_at' => $at, 'verified_by' => $by, 'verification_note' => $note]);
    }

    public function lineTotals(PropertyId $property, string $from, string $to): array
    {
        return $this->rows(DB::table('fin_revenue_lines')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])
            ->groupBy('business_date', 'outlet_code', 'outlet_name')->orderBy('business_date')->orderBy('outlet_code')
            ->get(['business_date', 'outlet_code', 'outlet_name', DB::raw('SUM(base_minor) as base_minor'), DB::raw('SUM(service_charge_minor) as service_charge_minor'), DB::raw('SUM(tax_minor) as tax_minor'), DB::raw('SUM(total_minor) as total_minor')]));
    }

    public function paymentTotals(PropertyId $property, string $from, string $to): array
    {
        return $this->rows(DB::table('fin_payment_lines')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])
            ->groupBy('method')->orderBy('method')
            ->get(['method', DB::raw('SUM(received_minor) as received_minor'), DB::raw('SUM(paid_back_minor) as paid_back_minor'), DB::raw('SUM(entries) as entries')]));
    }

    public function addCashShift(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_cash_shifts', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function cashShifts(PropertyId $property, ?string $from, ?string $to, ?string $only, int $limit): array
    {
        return $this->rows($this->shiftQuery($property)
            ->when($from !== null, static fn ($q) => $q->where('s.closed_business_date', '>=', $from))
            ->when($to !== null, static fn ($q) => $q->where('s.closed_business_date', '<=', $to))
            ->when($only === 'waiting', static fn ($q) => $q->whereNull('d.id'))
            ->when($only === 'received', static fn ($q) => $q->whereNotNull('d.id'))
            ->orderByDesc('s.closed_business_date')->orderByDesc('s.occurred_at')->orderByDesc('s.id')->limit($limit)->get());
    }

    public function cashShift(PropertyId $property, string $id): ?array
    {
        return $this->row($this->shiftQuery($property)->where('s.id', $id)->first());
    }

    public function lockCashShift(PropertyId $property, string $id): void
    {
        DB::table('fin_cash_shifts')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function addDeposit(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_cash_deposits', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addException(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fin_cash_exceptions')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'open', 'lock_version' => 0, 'created_at' => $at]);
    }

    public function exceptions(PropertyId $property, ?string $status): array
    {
        return $this->rows($this->exceptionQuery($property)->when($status !== null, static fn ($q) => $q->where('x.status', $status))
            ->orderByRaw("x.status = 'open' DESC")->orderBy('x.created_at')->orderBy('x.id')->get());
    }

    public function exception(PropertyId $property, string $id): ?array
    {
        return $this->row($this->exceptionQuery($property)->where('x.id', $id)->first());
    }

    public function settleException(PropertyId $property, string $id, int $lock, string $status, string $resolution, string $by, DateTimeImmutable $at): bool
    {
        return DB::table('fin_cash_exceptions')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->where('lock_version', $lock)
            ->update(['status' => $status, 'resolution' => $resolution, 'resolved_by' => $by, 'resolved_at' => $at, 'lock_version' => $lock + 1]) === 1;
    }

    public function dayBlockers(PropertyId $property, string $date): array
    {
        $pid = $property->toString();
        $waiting = DB::table('fin_cash_shifts as s')->leftJoin('fin_cash_deposits as d', 'd.cash_shift_id', '=', 's.id')->where('s.property_id', $pid)->where('s.closed_business_date', $date)->whereNull('d.id')->count();
        $open = DB::table('fin_cash_exceptions as x')->join('fin_cash_deposits as d', 'd.id', '=', 'x.deposit_id')->join('fin_cash_shifts as s', 's.id', '=', 'd.cash_shift_id')
            ->where('x.property_id', $pid)->where('x.status', 'open')->where('s.closed_business_date', $date)->count();

        $exceptions = DB::table('fin_exceptions')->where('property_id', $pid)->where('status', 'open')->where('business_date', $date)->count();

        return ['waiting' => $waiting, 'open' => $open, 'exceptions' => $exceptions];
    }

    public function openExceptionCount(PropertyId $property): int
    {
        return DB::table('fin_cash_exceptions')->where('property_id', $property->toString())->where('status', 'open')->count();
    }

    private function shiftQuery(PropertyId $property): Builder
    {
        return DB::table('fin_cash_shifts as s')->leftJoin('fin_cash_deposits as d', 'd.cash_shift_id', '=', 's.id')->leftJoin('fin_cash_exceptions as x', 'x.deposit_id', '=', 'd.id')
            ->where('s.property_id', $property->toString())
            ->select(['s.*', 'd.id as deposit_id', 'd.number as deposit_number', 'd.deposited_minor', 'd.variance_minor as deposit_variance_minor', 'd.reason as deposit_reason', 'd.note as deposit_note', 'd.received_by', 'd.business_date as deposit_date', 'x.status as exception_status']);
    }

    private function exceptionQuery(PropertyId $property): Builder
    {
        return DB::table('fin_cash_exceptions as x')->join('fin_cash_deposits as d', 'd.id', '=', 'x.deposit_id')->join('fin_cash_shifts as s', 's.id', '=', 'd.cash_shift_id')
            ->where('x.property_id', $property->toString())
            ->select(['x.*', 'd.number as deposit_number', 'd.reason', 'd.received_by', 's.number as shift_number', 's.cashier_id', 's.currency', 's.closed_business_date']);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): bool
    {
        try {
            DB::table($table)->insert($row);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function row(?object $row): ?array
    {
        return $row === null ? null : (array) $row;
    }

    /** @param iterable<object> $rows @return list<array<string, mixed>> */
    private function rows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    public function addPosSale(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_pos_sales', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function dayExists(PropertyId $property, string $date): bool
    {
        return DB::table('fin_revenue_days')->where('property_id', $property->toString())->where('business_date', $date)->exists();
    }

    public function addPosRefund(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_pos_refunds', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function posSalesOf(PropertyId $property, string $date): array
    {
        $out = [];

        foreach (DB::table('fin_pos_sales')->where('property_id', $property->toString())->where('business_date', $date)->where('room_minor', 0)->where('late', false)->groupBy('source')
            ->get(['source', DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as sc'), DB::raw('SUM(tax_minor) as tax'), DB::raw('SUM(total_minor) as total')]) as $r) {
            $out[(string) $r->source] = ['source' => (string) $r->source, 'base_minor' => (int) $r->base, 'service_charge_minor' => (int) $r->sc, 'tax_minor' => (int) $r->tax, 'total_minor' => (int) $r->total];
        }

        foreach (DB::table('fin_pos_refunds')->where('property_id', $property->toString())->where('business_date', $date)->where('late', false)->groupBy('source')
            ->get(['source', DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as sc'), DB::raw('SUM(tax_minor) as tax'), DB::raw('SUM(total_minor) as total')]) as $r) {
            $s = (string) $r->source;
            $out[$s] ??= ['source' => $s, 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];
            $out[$s]['base_minor'] -= (int) $r->base;
            $out[$s]['service_charge_minor'] -= (int) $r->sc;
            $out[$s]['tax_minor'] -= (int) $r->tax;
            $out[$s]['total_minor'] -= (int) $r->total;
        }

        ksort($out);

        return array_values($out);
    }

    public function posPaymentsOf(PropertyId $property, string $date): array
    {
        $sales = DB::table('fin_pos_sales')->where('property_id', $property->toString())->where('business_date', $date)->where('room_minor', 0)->where('late', false)
            ->first([DB::raw('COALESCE(SUM(cash_minor), 0) as cash'), DB::raw('COALESCE(SUM(cash_minor > 0), 0) as cash_n'), DB::raw('COALESCE(SUM(card_minor), 0) as card'), DB::raw('COALESCE(SUM(card_minor > 0), 0) as card_n'), DB::raw('COALESCE(SUM(qris_minor), 0) as qris'), DB::raw('COALESCE(SUM(qris_minor > 0), 0) as qris_n')]);
        $refunds = DB::table('fin_pos_refunds')->where('property_id', $property->toString())->where('business_date', $date)->where('late', false)
            ->first([DB::raw('COALESCE(SUM(cash_minor), 0) as cash'), DB::raw('COALESCE(SUM(card_minor), 0) as card'), DB::raw('COALESCE(SUM(qris_minor), 0) as qris')]);
        $out = [];

        foreach (['cash', 'card', 'qris'] as $method) {
            $received = (int) ($sales->{$method} ?? 0);
            $back = (int) ($refunds->{$method} ?? 0);

            if ($received !== 0 || $back !== 0) {
                $out[] = ['method' => $method, 'amount_minor' => $received, 'paid_back_minor' => $back, 'count' => (int) ($sales->{$method.'_n'} ?? 0)];
            }
        }

        return $out;
    }
}
