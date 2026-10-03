<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\SettlementStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseSettlementStore implements SettlementStore
{
    public function settlements(PropertyId $property, int $limit): array
    {
        return DB::table('fin_settlements')->where('property_id', $property->toString())->orderByDesc('settled_on')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fin_settlements')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function overlapping(PropertyId $property, string $method, string $provider, string $from, string $to): array
    {
        return DB::table('fin_settlements')->where('property_id', $property->toString())->where('method', $method)->where('provider', $provider)->where('covers_from', '<=', $to)->where('covers_to', '>=', $from)
            ->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function receivedNet(PropertyId $property, string $method, string $from, string $to): int
    {
        return (int) DB::table('fin_payment_lines')->where('property_id', $property->toString())->where('method', $method)->whereBetween('business_date', [$from, $to])->sum(DB::raw('received_minor - paid_back_minor'));
    }

    public function unbookedDays(PropertyId $property, string $from, string $to): array
    {
        $booked = DB::table('fin_revenue_days')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->pluck('business_date')->map(static fn ($d): string => substr((string) $d, 0, 10))->flip()->all();
        $missing = [];

        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            if (! isset($booked[$d->format('Y-m-d')])) {
                $missing[] = $d->format('Y-m-d');
            }
        }

        return $missing;
    }

    public function receiptsByDay(PropertyId $property, string $since): array
    {
        return DB::table('fin_payment_lines')->where('property_id', $property->toString())->whereIn('method', ['qris', 'card'])->where('business_date', '>=', $since)->orderBy('business_date')->orderBy('method')
            ->get(['business_date', 'method', DB::raw('received_minor - paid_back_minor as net')])->map(static fn (object $r): array => ['business_date' => substr((string) $r->business_date, 0, 10), 'method' => (string) $r->method, 'net_minor' => (int) $r->net])->all();
    }

    public function coveredSince(PropertyId $property, string $since): array
    {
        return DB::table('fin_settlements')->where('property_id', $property->toString())->where('covers_to', '>=', $since)->get(['method', 'provider', 'covers_from', 'covers_to'])
            ->map(static fn (object $r): array => ['method' => (string) $r->method, 'provider' => (string) $r->provider, 'covers_from' => substr((string) $r->covers_from, 0, 10), 'covers_to' => substr((string) $r->covers_to, 0, 10)])->all();
    }
}
