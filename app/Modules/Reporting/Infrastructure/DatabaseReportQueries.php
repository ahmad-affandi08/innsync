<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ReportQueries;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The read side. It only selects, joins across the operational tables, and never writes; the identity fields it returns are
 * decrypted here so the Application layer decides who sees them.
 */
final readonly class DatabaseReportQueries implements ReportQueries
{
    private const ALERT_EXAMPLES = 5;

    public function __construct(private FieldCipher $cipher) {}

    public function roomCounts(PropertyId $property, BusinessDate $date): array
    {
        $pid = $property->toString();
        $day = $date->toString();
        $stays = DB::table('stays')->where('property_id', $pid)->where('status', 'in_house');

        return [
            'total' => DB::table('rooms')->where('property_id', $pid)->where('is_active', true)->count(),
            'blocked' => DB::table('room_blocks')->where('property_id', $pid)->whereNull('released_at')->where('start_date', '<=', $day)->where('end_date', '>=', $day)->distinct()->count('room_id'),
            'occupied' => (clone $stays)->count(),
            'guests_in_house' => (int) (clone $stays)->selectRaw('COALESCE(SUM(adults + children), 0) as n')->value('n'),
        ];
    }

    public function movements(PropertyId $property, BusinessDate $date): array
    {
        $pid = $property->toString();
        $day = $date->toString();

        return [
            'arrivals_expected' => DB::table('reservations')->where('property_id', $pid)->whereIn('status', ['tentative', 'confirmed', 'guaranteed'])->where('arrival_date', $day)->count(),
            'arrivals_checked_in' => DB::table('stays')->where('property_id', $pid)->where('checked_in_business_date', $day)->count(),
            'departures_expected' => DB::table('stays')->where('property_id', $pid)->where('status', 'in_house')->where('expected_departure', $day)->count(),
            'departures_done' => DB::table('stays')->where('property_id', $pid)->where('checked_out_business_date', $day)->count(),
        ];
    }

    public function activity(PropertyId $property, ReportPeriod $period): array
    {
        $range = [$period->from->toString(), $period->to->toString()];

        return [
            'checked_in' => DB::table('stays')->where('property_id', $property->toString())->whereBetween('checked_in_business_date', $range)->count(),
            'checked_out' => DB::table('stays')->where('property_id', $property->toString())->whereBetween('checked_out_business_date', $range)->count(),
        ];
    }

    public function reservationsCreated(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): int
    {
        return DB::table('reservations')->where('property_id', $property->toString())->where('created_at', '>=', $fromUtc)->where('created_at', '<', $toUtc)->count();
    }

    public function revenue(PropertyId $property, ReportPeriod $period): array
    {
        $result = ['room' => self::zero(), 'laundry' => self::zero(), 'other' => self::zero(), 'net' => self::zero()];
        $rows = DB::table('folio_postings')
            ->where('property_id', $property->toString())->whereBetween('business_date', [$period->from->toString(), $period->to->toString()])
            ->whereIn('entry_type', ['charge', 'reversal'])
            ->where(static fn ($q) => $q->where('base_minor', '<>', 0)->orWhere('service_charge_minor', '<>', 0)->orWhere('tax_minor', '<>', 0))
            ->groupBy('source')
            ->get(['source', DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as service_charge'), DB::raw('SUM(tax_minor) as tax'), DB::raw('SUM(total_minor) as total')]);

        foreach ($rows as $row) {
            $bucket = match ($row->source) {
                'night_audit' => 'room',
                'laundry' => 'laundry',
                default => 'other',
            };

            foreach (['base' => (int) $row->base, 'service_charge' => (int) $row->service_charge, 'tax' => (int) $row->tax, 'total' => (int) $row->total] as $key => $value) {
                $result[$bucket][$key] += $value;
                $result['net'][$key] += $value;
            }
        }

        return $result;
    }

    public function alerts(PropertyId $property, BusinessDate $today, DateTimeImmutable $nowUtc): array
    {
        $pid = $property->toString();
        $day = $today->toString();
        $alerts = [];

        $oversold = DB::table('reservations')->where('property_id', $pid)->where('oversold', true)->whereIn('status', ['tentative', 'confirmed', 'guaranteed', 'checked_in'])->where('departure_date', '>', $day);
        $alerts['oversold'] = ['count' => (clone $oversold)->count(), 'items' => (clone $oversold)->orderBy('arrival_date')->limit(self::ALERT_EXAMPLES)->pluck('number')->all()];

        $late = DB::table('stays as s')->join('reservations as r', 'r.id', '=', 's.reservation_id')->join('rooms', 'rooms.id', '=', 's.room_id')
            ->where('s.property_id', $pid)->where('s.status', 'in_house')->where('s.expected_departure', '<=', $day);
        $alerts['due_departures'] = ['count' => (clone $late)->count(), 'items' => (clone $late)->orderBy('rooms.number')->limit(self::ALERT_EXAMPLES)->get(['rooms.number as room', 'r.number'])->map(static fn ($r): string => 'Room '.$r->room.' · '.$r->number)->all()];

        $balance = DB::table('stays as s')->join('folios as f', 'f.reservation_id', '=', 's.reservation_id')->join('rooms', 'rooms.id', '=', 's.room_id')
            ->where('s.property_id', $pid)->where('s.status', 'in_house')->where('s.expected_departure', '<=', $day)->where('f.status', 'open')->where('f.balance_minor', '<>', 0);
        $alerts['unsettled_departures'] = ['count' => (clone $balance)->count(), 'items' => (clone $balance)->orderBy('rooms.number')->limit(self::ALERT_EXAMPLES)->get(['rooms.number as room', 'f.number'])->map(static fn ($r): string => 'Room '.$r->room.' · '.$r->number)->all()];

        $stale = DB::table('reservations')->where('property_id', $pid)->whereIn('status', ['tentative', 'confirmed', 'guaranteed'])->where('arrival_date', '<', $day);
        $alerts['stale_arrivals'] = ['count' => (clone $stale)->count(), 'items' => (clone $stale)->orderBy('arrival_date')->limit(self::ALERT_EXAMPLES)->pluck('number')->all()];

        $laundry = DB::table('laundry_orders')->where('property_id', $pid)->whereIn('status', ['sent', 'received', 'washing', 'drying', 'ironing'])->where('promised_at', '<', $nowUtc);
        $alerts['laundry_overdue'] = ['count' => (clone $laundry)->count(), 'items' => (clone $laundry)->orderBy('promised_at')->limit(self::ALERT_EXAMPLES)->pluck('number')->all()];

        $notReady = DB::table('housekeeping_rooms as h')->join('rooms', 'rooms.id', '=', 'h.room_id')->where('h.property_id', $pid)->whereIn('h.status', ['dirty', 'rework'])
            ->whereNotIn('h.room_id', DB::table('stays')->where('property_id', $pid)->where('status', 'in_house')->select('room_id'));
        $alerts['rooms_not_ready'] = ['count' => (clone $notReady)->count(), 'items' => (clone $notReady)->orderBy('rooms.number')->limit(self::ALERT_EXAMPLES)->pluck('rooms.number')->map(static fn ($n): string => 'Room '.$n)->all()];

        return $alerts;
    }

    public function registrations(PropertyId $property, ReportPeriod $period, ?string $nationality, bool $foreignOnly): array
    {
        $query = DB::table('stays as s')
            ->join('guests as g', 'g.id', '=', 's.guest_id')->join('rooms', 'rooms.id', '=', 's.room_id')->join('reservations as r', 'r.id', '=', 's.reservation_id')
            ->where('s.property_id', $property->toString())->whereBetween('s.checked_in_business_date', [$period->from->toString(), $period->to->toString()]);

        if ($nationality !== null && $nationality !== '') {
            $query->where('g.nationality', strtoupper($nationality));
        }

        if ($foreignOnly) {
            $query->where('g.nationality', '<>', 'ID');
        }

        return $query->orderBy('s.checked_in_business_date')->orderBy('rooms.number')->get([
            's.id as stay_id', 'r.number as reservation', 'rooms.number as room', 'g.full_name', 'g.nationality', 'g.id_type', 'g.id_number_enc', 'g.id_valid_until', 'g.visa_number_enc', 'g.address_enc',
            's.adults', 's.children', 's.checked_in_business_date', 's.expected_departure', 's.checked_out_business_date',
        ])->map(fn ($r): array => [
            'stay_id' => $r->stay_id, 'reservation' => $r->reservation, 'room' => $r->room, 'full_name' => $r->full_name, 'nationality' => $r->nationality, 'id_type' => $r->id_type,
            'id_number' => $this->cipher->open($r->id_number_enc), 'id_valid_until' => $r->id_valid_until === null ? null : substr((string) $r->id_valid_until, 0, 10),
            'visa_number' => $r->visa_number_enc === null ? null : $this->cipher->open($r->visa_number_enc), 'address' => $this->cipher->open($r->address_enc),
            'adults' => (int) $r->adults, 'children' => (int) $r->children, 'checked_in' => substr((string) $r->checked_in_business_date, 0, 10),
            'expected_departure' => substr((string) $r->expected_departure, 0, 10), 'checked_out' => $r->checked_out_business_date === null ? null : substr((string) $r->checked_out_business_date, 0, 10),
        ])->all();
    }

    public function paymentsByMethod(PropertyId $property, ReportPeriod $period): array
    {
        $rows = DB::table('folio_postings')
            ->where('property_id', $property->toString())->whereBetween('business_date', [$period->from->toString(), $period->to->toString()])
            ->whereIn('entry_type', ['payment', 'refund', 'reversal'])->where('base_minor', 0)->where('service_charge_minor', 0)->where('tax_minor', 0)
            ->groupBy('payment_method')
            ->get(['payment_method', DB::raw('SUM(CASE WHEN total_minor < 0 THEN -total_minor ELSE 0 END) as received'), DB::raw('SUM(CASE WHEN total_minor > 0 THEN total_minor ELSE 0 END) as paid_back'), DB::raw('COUNT(*) as n')]);

        $result = [];

        foreach ($rows as $row) {
            $result[] = ['method' => (string) ($row->payment_method ?? 'other'), 'received_minor' => (int) $row->received, 'paid_back_minor' => (int) $row->paid_back, 'net_minor' => (int) $row->received - (int) $row->paid_back, 'count' => (int) $row->n];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['method'], $b['method']));

        return $result;
    }

    public function closedDays(PropertyId $property, ReportPeriod $period): array
    {
        return DB::table('night_audits')->where('property_id', $property->toString())->whereBetween('business_date', [$period->from->toString(), $period->to->toString()])->orderBy('business_date')->get()
            ->map(static fn ($r): array => ['business_date' => substr((string) $r->business_date, 0, 10), 'report' => json_decode((string) $r->report, true, 512, JSON_THROW_ON_ERROR)])->all();
    }

    public function auditTrail(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $filters, int $limit, int $offset): array
    {
        $query = DB::table('audit_entries as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.property_id', $property->toString())->where('a.occurred_at', '>=', $fromUtc)->where('a.occurred_at', '<', $toUtc);

        if (($filters['actor_id'] ?? null) !== null && $filters['actor_id'] !== '') {
            $query->where('a.actor_id', strtolower($filters['actor_id']));
        }

        if (($filters['module'] ?? null) !== null && $filters['module'] !== '') {
            $query->where('a.action', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['module']).'.%');
        }

        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $query->where('a.action', $filters['action']);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit($limit)->offset($offset)
            ->get(['a.id', 'a.occurred_at', 'a.action', 'a.aggregate_type', 'a.aggregate_id', 'a.reason', 'a.actor_id', 'u.name as actor_name'])
            ->map(static fn ($r): array => [
                'id' => $r->id, 'occurred_at' => (string) $r->occurred_at, 'action' => $r->action, 'aggregate_type' => $r->aggregate_type, 'aggregate_id' => $r->aggregate_id,
                'reason' => $r->reason, 'actor_id' => $r->actor_id, 'actor_name' => $r->actor_name,
            ])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, int> */
    private static function zero(): array
    {
        return ['base' => 0, 'service_charge' => 0, 'tax' => 0, 'total' => 0];
    }
}
