<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\NightAudit;

use App\Modules\FrontOffice\Application\NightAudit\NightAuditRefused;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseNightAuditRepository implements NightAuditRepository
{
    public function pendingArrivals(PropertyId $property, BusinessDate $date, int $limit): array
    {
        return DB::table('reservations')
            ->where('property_id', $property->toString())
            ->whereIn('status', ['tentative', 'confirmed', 'guaranteed'])
            ->where('arrival_date', '<=', $date->toString())
            ->orderBy('arrival_date')->orderBy('number')->limit($limit)
            ->get(['id', 'number', 'arrival_date'])
            ->map(static fn ($r): array => ['reservation_id' => $r->id, 'number' => $r->number, 'arrival' => substr((string) $r->arrival_date, 0, 10)])->all();
    }

    public function sameDayStays(PropertyId $property, BusinessDate $date, int $limit): array
    {
        return DB::table('stays as s')
            ->join('reservations as r', 'r.id', '=', 's.reservation_id')
            ->where('s.property_id', $property->toString())
            ->where('s.checked_in_business_date', $date->toString())
            ->where('s.checked_out_business_date', $date->toString())
            ->orderBy('r.number')->limit($limit)
            ->get(['s.id as stay_id', 'r.number', 's.room_id'])
            ->map(static fn ($r): array => ['stay_id' => $r->stay_id, 'number' => $r->number, 'room_id' => $r->room_id])->all();
    }

    public function dayTotals(PropertyId $property, BusinessDate $date): array
    {
        $day = $date->toString();
        $revenue = ['room' => self::zero(), 'other' => self::zero(), 'net' => self::zero()];

        // Revenue is every charge and every reversal of a charge (reversals carry the parts negated) of the day.
        $rows = DB::table('folio_postings')
            ->where('property_id', $property->toString())->where('business_date', $day)
            ->whereIn('entry_type', ['charge', 'reversal'])
            ->where(static fn ($q) => $q->where('base_minor', '<>', 0)->orWhere('service_charge_minor', '<>', 0)->orWhere('tax_minor', '<>', 0))
            ->groupBy(DB::raw("(source = 'night_audit')"))
            ->get([DB::raw("(source = 'night_audit') as is_room"), DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as service_charge'), DB::raw('SUM(tax_minor) as tax'), DB::raw('SUM(total_minor) as total')]);

        foreach ($rows as $row) {
            $bucket = (int) $row->is_room === 1 ? 'room' : 'other';
            $part = ['base' => (int) $row->base, 'service_charge' => (int) $row->service_charge, 'tax' => (int) $row->tax, 'total' => (int) $row->total];

            foreach ($part as $key => $value) {
                $revenue[$bucket][$key] += $value;
                $revenue['net'][$key] += $value;
            }
        }

        // Money in minus money out by method: payments are negative on the folio, refunds and reversals of payments positive.
        $collected = [];

        foreach (DB::table('folio_postings')
            ->where('property_id', $property->toString())->where('business_date', $day)
            ->whereIn('entry_type', ['payment', 'refund', 'reversal'])
            ->where('base_minor', 0)->where('service_charge_minor', 0)->where('tax_minor', 0)
            ->groupBy('payment_method')
            ->get(['payment_method', DB::raw('SUM(total_minor) as total')]) as $row) {
            $collected[(string) ($row->payment_method ?? 'other')] = -(int) $row->total;
        }

        ksort($collected);

        return [
            'revenue' => $revenue,
            'collected' => $collected,
            'arrivals' => DB::table('stays')->where('property_id', $property->toString())->where('checked_in_business_date', $day)->count(),
            'departures' => DB::table('stays')->where('property_id', $property->toString())->where('checked_out_business_date', $day)->count(),
        ];
    }

    public function sourceTotals(PropertyId $property, BusinessDate $date): array
    {
        $rows = DB::table('folio_postings')
            ->where('property_id', $property->toString())->where('business_date', $date->toString())
            ->whereIn('entry_type', ['charge', 'reversal'])
            ->where(static fn ($q) => $q->where('base_minor', '<>', 0)->orWhere('service_charge_minor', '<>', 0)->orWhere('tax_minor', '<>', 0))
            ->groupBy('source')->orderBy('source')
            ->get(['source', DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as service_charge'), DB::raw('SUM(tax_minor) as tax'), DB::raw('SUM(total_minor) as total')]);

        return $rows->map(static fn ($r): array => [
            'source' => (string) $r->source, 'base_minor' => (int) $r->base, 'service_charge_minor' => (int) $r->service_charge, 'tax_minor' => (int) $r->tax, 'total_minor' => (int) $r->total,
        ])->all();
    }

    public function paymentTotals(PropertyId $property, BusinessDate $date): array
    {
        $rows = DB::table('folio_postings')
            ->where('property_id', $property->toString())->where('business_date', $date->toString())
            ->whereIn('entry_type', ['payment', 'refund', 'reversal'])->where('base_minor', 0)->where('service_charge_minor', 0)->where('tax_minor', 0)
            ->groupBy('payment_method')->orderBy('payment_method')
            ->get(['payment_method', DB::raw('SUM(CASE WHEN total_minor < 0 THEN -total_minor ELSE 0 END) as received'), DB::raw('SUM(CASE WHEN total_minor > 0 THEN total_minor ELSE 0 END) as paid_back'), DB::raw('COUNT(*) as n')]);

        return $rows->map(static fn ($r): array => ['method' => (string) ($r->payment_method ?? 'other'), 'received_minor' => (int) $r->received, 'paid_back_minor' => (int) $r->paid_back, 'count' => (int) $r->n])->all();
    }

    public function record(PropertyId $property, string $id, BusinessDate $date, BusinessDate $next, array $report, array $waivers, string $actorId, DateTimeImmutable $at): void
    {
        try {
            DB::table('night_audits')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'business_date' => $date->toString(), 'next_business_date' => $next->toString(),
                'report' => json_encode($report, JSON_THROW_ON_ERROR), 'waivers' => json_encode($waivers, JSON_THROW_ON_ERROR), 'run_by' => $actorId, 'completed_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw NightAuditRefused::alreadyClosed($date->toString());
        }
    }

    public function find(PropertyId $property, BusinessDate $date): ?array
    {
        $row = DB::table('night_audits')->where('property_id', $property->toString())->where('business_date', $date->toString())->first();

        return $row === null ? null : self::view($row);
    }

    public function history(PropertyId $property, int $limit): array
    {
        return DB::table('night_audits')->where('property_id', $property->toString())->orderByDesc('business_date')->limit($limit)->get()
            ->map(static fn ($r): array => self::view($r))->all();
    }

    /** @return array<string, int> */
    private static function zero(): array
    {
        return ['base' => 0, 'service_charge' => 0, 'tax' => 0, 'total' => 0];
    }

    /** @return array<string, mixed> */
    private static function view(object $row): array
    {
        return [
            'id' => $row->id,
            'business_date' => substr((string) $row->business_date, 0, 10),
            'next_business_date' => substr((string) $row->next_business_date, 0, 10),
            'completed_at' => (string) $row->completed_at,
            'report' => json_decode((string) $row->report, true, 512, JSON_THROW_ON_ERROR),
            'waivers' => json_decode((string) $row->waivers, true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
