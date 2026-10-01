<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Cashier;

use App\Modules\FrontOffice\Application\Cashier\CashierRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCashierRepository implements CashierRepository
{
    public function settings(PropertyId $property): array
    {
        $row = DB::table('cashier_settings')->where('property_id', $property->toString())->first();

        return ['require_open_shift' => $row !== null && (bool) $row->require_open_shift, 'lock_version' => $row === null ? 0 : (int) $row->lock_version];
    }

    public function saveSettings(PropertyId $property, bool $requireOpenShift, int $expectedLockVersion, string $actorId, DateTimeImmutable $now): bool
    {
        $pid = $property->toString();
        $exists = DB::table('cashier_settings')->where('property_id', $pid)->exists();

        if (! $exists) {
            if ($expectedLockVersion !== 0) {
                return false;
            }

            try {
                DB::table('cashier_settings')->insert(['property_id' => $pid, 'require_open_shift' => $requireOpenShift, 'lock_version' => 1, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
            } catch (QueryException) {
                return false;
            }

            return true;
        }

        return DB::table('cashier_settings')->where('property_id', $pid)->where('lock_version', $expectedLockVersion)
            ->update(['require_open_shift' => $requireOpenShift, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $actorId, 'updated_at' => $now]) === 1;
    }

    public function open(PropertyId $property, string $id, string $number, string $cashierId, string $currency, DateTimeImmutable $at, string $businessDate, int $floatMinor): bool
    {
        try {
            DB::table('cashier_shifts')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'number' => $number, 'cashier_id' => $cashierId, 'status' => 'open', 'currency_code' => $currency,
                'opened_at' => $at, 'opened_business_date' => $businessDate, 'opening_float_minor' => $floatMinor, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'cashier_shifts_one_open_per_cashier')) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('cashier_shifts')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function openOf(PropertyId $property, string $cashierId): ?array
    {
        $row = DB::table('cashier_shifts')->where('property_id', $property->toString())->where('cashier_id', $cashierId)->where('status', 'open')->first();

        return $row === null ? null : self::shape($row);
    }

    public function search(PropertyId $property, ?string $status, ?string $cashierId, int $limit): array
    {
        $query = DB::table('cashier_shifts')->where('property_id', $property->toString());

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($cashierId !== null) {
            $query->where('cashier_id', strtolower($cashierId));
        }

        return $query->orderByDesc('opened_at')->orderByDesc('id')->limit($limit)->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function openShifts(PropertyId $property, int $limit): array
    {
        return DB::table('cashier_shifts')->where('property_id', $property->toString())->where('status', 'open')->orderBy('opened_at')->limit($limit)
            ->get(['id', 'number', 'cashier_id'])->map(static fn ($r): array => ['id' => $r->id, 'number' => $r->number, 'cashier_id' => $r->cashier_id])->all();
    }

    public function addDrop(PropertyId $property, string $shiftId, string $id, int $amountMinor, ?string $reference, ?string $note, string $actorId, DateTimeImmutable $at): string
    {
        try {
            DB::table('cash_drops')->insert(['id' => $id, 'property_id' => $property->toString(), 'shift_id' => $shiftId, 'amount_minor' => $amountMinor, 'reference' => $reference, 'note' => $note, 'created_by' => $actorId, 'created_at' => $at]);
        } catch (QueryException $e) {
            if ($reference !== null && str_contains($e->getMessage(), 'cash_drops_shift_id_reference_unique')) {
                return 'duplicate';
            }

            throw $e;
        }

        return 'added';
    }

    public function dropByReference(PropertyId $property, string $shiftId, string $reference): ?array
    {
        $row = DB::table('cash_drops')->where('property_id', $property->toString())->where('shift_id', $shiftId)->where('reference', $reference)->first();

        return $row === null ? null : ['id' => $row->id, 'amount_minor' => (int) $row->amount_minor];
    }

    public function drops(PropertyId $property, string $shiftId): array
    {
        return DB::table('cash_drops')->where('property_id', $property->toString())->where('shift_id', $shiftId)->orderBy('created_at')->orderBy('id')->get()
            ->map(static fn ($r): array => ['id' => $r->id, 'amount_minor' => (int) $r->amount_minor, 'reference' => $r->reference, 'note' => $r->note, 'created_at' => (new DateTimeImmutable((string) $r->created_at, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')])->all();
    }

    public function attribute(PropertyId $property, string $shiftId, string $postingId, DateTimeImmutable $at): void
    {
        DB::table('cashier_shift_postings')->insert(['posting_id' => $postingId, 'property_id' => $property->toString(), 'shift_id' => $shiftId, 'created_at' => $at]);
    }

    public function receipts(PropertyId $property, string $shiftId): array
    {
        $rows = DB::table('cashier_shift_postings as sp')->join('folio_postings as p', 'p.id', '=', 'sp.posting_id')
            ->where('sp.property_id', $property->toString())->where('sp.shift_id', $shiftId)
            ->groupBy('p.payment_method')
            ->get(['p.payment_method', DB::raw('SUM(CASE WHEN p.total_minor < 0 THEN -p.total_minor ELSE 0 END) as received'), DB::raw('SUM(CASE WHEN p.total_minor > 0 THEN p.total_minor ELSE 0 END) as paid_back'), DB::raw('COUNT(*) as n')]);
        $result = [];

        foreach ($rows as $row) {
            $result[] = ['method' => (string) ($row->payment_method ?? 'other'), 'received_minor' => (int) $row->received, 'paid_back_minor' => (int) $row->paid_back, 'count' => (int) $row->n];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['method'], $b['method']));

        return $result;
    }

    public function close(PropertyId $property, string $shiftId, int $expectedLockVersion, string $closedBy, DateTimeImmutable $at, string $businessDate, int $expectedCash, int $countedCash, ?string $reason, array $totals): bool
    {
        return DB::table('cashier_shifts')->where('property_id', $property->toString())->where('id', $shiftId)->where('status', 'open')->where('lock_version', $expectedLockVersion)->update([
            'status' => 'closed', 'closed_at' => $at, 'closed_business_date' => $businessDate, 'closed_by' => $closedBy, 'expected_cash_minor' => $expectedCash, 'counted_cash_minor' => $countedCash,
            'variance_minor' => $countedCash - $expectedCash, 'variance_reason' => $reason, 'totals' => json_encode($totals, JSON_THROW_ON_ERROR), 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
        ]) === 1;
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r->id, 'number' => $r->number, 'cashier_id' => $r->cashier_id, 'status' => $r->status, 'currency' => $r->currency_code,
            'opened_at' => $utc($r->opened_at), 'opened_business_date' => substr((string) $r->opened_business_date, 0, 10), 'opening_float_minor' => (int) $r->opening_float_minor,
            'closed_at' => $utc($r->closed_at), 'closed_business_date' => $r->closed_business_date === null ? null : substr((string) $r->closed_business_date, 0, 10), 'closed_by' => $r->closed_by,
            'expected_cash_minor' => $r->expected_cash_minor === null ? null : (int) $r->expected_cash_minor, 'counted_cash_minor' => $r->counted_cash_minor === null ? null : (int) $r->counted_cash_minor,
            'variance_minor' => $r->variance_minor === null ? null : (int) $r->variance_minor, 'variance_reason' => $r->variance_reason,
            'totals' => $r->totals === null ? null : json_decode((string) $r->totals, true, 512, JSON_THROW_ON_ERROR), 'lock_version' => (int) $r->lock_version,
        ];
    }
}
