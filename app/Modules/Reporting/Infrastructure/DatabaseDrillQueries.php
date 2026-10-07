<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\DrillQueries;
use App\Modules\Reporting\Application\RevenueScope;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reads the tables of other contexts directly, as the other report queries do (read model, no writes). */
final readonly class DatabaseDrillQueries implements DrillQueries
{
    private const STAYS = ['s.id', 'r.number as reservation', 'rooms.number as room', 's.checked_in_business_date', 's.expected_departure', 's.checked_out_business_date', 's.adults', 's.children'];

    public function inHouse(PropertyId $property, int $limit): array
    {
        return $this->stays($this->stayQuery($property)->where('s.status', 'in_house'), 'rooms.number', $limit);
    }

    public function blockedRooms(PropertyId $property, BusinessDate $date, int $limit, ?string $kind = null): array
    {
        $day = $date->toString();
        $query = DB::table('room_blocks as b')->join('rooms', 'rooms.id', '=', 'b.room_id')->where('b.property_id', $property->toString())->whereNull('b.released_at')
            ->where('b.start_date', '<=', $day)->where('b.end_date', '>=', $day)->when($kind !== null, static fn ($q) => $q->where('b.kind', $kind));
        $total = (int) (clone $query)->distinct()->count('b.room_id');
        $rows = $query->groupBy('rooms.id', 'rooms.number')->orderBy('rooms.number')->limit($limit)
            ->get(['rooms.number as room', DB::raw('MIN(b.kind) as kind'), DB::raw('MIN(b.start_date) as start_date'), DB::raw('MAX(b.end_date) as end_date')])
            ->map(static fn ($r): array => ['room' => (string) $r->room, 'kind' => (string) $r->kind, 'from' => substr((string) $r->start_date, 0, 10), 'to' => substr((string) $r->end_date, 0, 10), 'href' => '/front-office/room-board'])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function expectedArrivals(PropertyId $property, BusinessDate $date, int $limit): array
    {
        return $this->reservations(DB::table('reservations as r')->where('r.property_id', $property->toString())->whereIn('r.status', ['tentative', 'confirmed', 'guaranteed'])->where('r.arrival_date', $date->toString()), 'r.number', $limit);
    }

    public function checkedIn(PropertyId $property, BusinessDate $from, BusinessDate $to, int $limit): array
    {
        return $this->stays($this->stayQuery($property)->whereBetween('s.checked_in_business_date', [$from->toString(), $to->toString()]), 'rooms.number', $limit);
    }

    public function checkedOut(PropertyId $property, BusinessDate $from, BusinessDate $to, int $limit): array
    {
        return $this->stays($this->stayQuery($property)->whereBetween('s.checked_out_business_date', [$from->toString(), $to->toString()]), 'rooms.number', $limit);
    }

    public function expectedDepartures(PropertyId $property, BusinessDate $date, int $limit): array
    {
        return $this->stays($this->stayQuery($property)->where('s.status', 'in_house')->where('s.expected_departure', $date->toString()), 'rooms.number', $limit);
    }

    public function reservationsMade(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, int $limit): array
    {
        return $this->reservations(DB::table('reservations as r')->where('r.property_id', $property->toString())->where('r.created_at', '>=', $fromUtc)->where('r.created_at', '<', $toUtc), 'r.number', $limit);
    }

    public function revenue(PropertyId $property, ReportPeriod $period, string $kind, ?RevenueScope $scope, int $limit): array
    {
        $pid = $property->toString();
        $range = [$period->from->toString(), $period->to->toString()];
        $outletOf = $this->outletOfSource($pid);
        $charged = static fn () => DB::table('folio_postings as p')->join('folios as f', 'f.id', '=', 'p.folio_id')->where('p.property_id', $pid)->whereBetween('p.business_date', $range)->whereIn('p.entry_type', ['charge', 'reversal'])
            ->where(static fn ($q) => $q->where('p.base_minor', '<>', 0)->orWhere('p.service_charge_minor', '<>', 0)->orWhere('p.tax_minor', '<>', 0));
        $sold = static fn () => DB::table('fin_pos_sales as s')->where('s.property_id', $pid)->whereBetween('s.business_date', $range)->where('s.room_minor', 0);

        // The sources that are posted in the period are few; which of them the figure and the scope allow is decided here, so that the database does the cutting and counting.
        $sources = array_values(array_unique([...$charged()->distinct()->pluck('p.source')->all(), ...$sold()->distinct()->pluck('s.source')->all()]));
        $kindOf = static fn (string $source): string => $source === 'night_audit' ? 'room' : ($source === 'laundry' ? 'laundry' : (isset($outletOf[$source]) ? 'outlet' : 'other'));
        $allowed = array_values(array_filter($sources, static fn (string $source): bool => ($scope === null || $scope->allows($kindOf($source), $source)) && ($kind === 'net' || $kind === $kindOf($source))));

        if ($allowed === []) {
            return ['rows' => [], 'total' => 0];
        }

        $folio = $charged()->whereIn('p.source', $allowed);
        $pos = $sold()->whereIn('s.source', $allowed);
        $total = (int) (clone $folio)->count() + (int) (clone $pos)->count();
        $rows = [];

        foreach ($folio->orderBy('p.business_date')->orderBy('p.id')->limit($limit)->get(['p.id', 'p.business_date', 'p.source', 'p.entry_type', 'p.description', 'f.number as document', 'f.id as folio_id', 'p.base_minor', 'p.service_charge_minor', 'p.tax_minor', 'p.total_minor']) as $p) {
            $rows[] = ['sort' => substr((string) $p->business_date, 0, 10).'|1|'.$p->id, 'kind' => $kindOf((string) $p->source), 'source' => (string) $p->source, 'date' => substr((string) $p->business_date, 0, 10), 'document' => (string) $p->document,
                'what' => ($p->entry_type === 'reversal' ? 'reversal: ' : '').$p->description, 'base' => (int) $p->base_minor, 'service_charge' => (int) $p->service_charge_minor, 'tax' => (int) $p->tax_minor, 'total' => (int) $p->total_minor,
                'href' => '/front-office/folios/'.$p->folio_id];
        }

        foreach ($pos->orderBy('s.business_date')->orderBy('s.id')->limit($limit)->get(['s.id', 's.business_date', 's.source', 's.bill_id', 's.bill_number', 's.outlet_code', 's.base_minor', 's.service_charge_minor', 's.tax_minor', 's.total_minor']) as $r) {
            $rows[] = ['sort' => substr((string) $r->business_date, 0, 10).'|2|'.$r->id, 'kind' => $kindOf((string) $r->source), 'source' => (string) $r->source, 'date' => substr((string) $r->business_date, 0, 10), 'document' => (string) $r->bill_number,
                'what' => 'sale at '.$r->outlet_code, 'base' => (int) $r->base_minor, 'service_charge' => (int) $r->service_charge_minor, 'tax' => (int) $r->tax_minor, 'total' => (int) $r->total_minor, 'href' => '/fnb/bills/'.$r->bill_id];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        return ['rows' => array_map(static function (array $r): array {
            unset($r['sort']);

            return $r;
        }, array_slice($rows, 0, $limit)), 'total' => $total];
    }

    public function owedPayables(PropertyId $property, int $limit): array
    {
        $settled = DB::table('ap_payments')->whereIn('status', ['paid', 'reversal'])->groupBy('payable_id')->selectRaw("payable_id, SUM(CASE WHEN status = 'reversal' THEN -amount_minor ELSE amount_minor END) as paid");
        $credited = DB::table('ap_credit_applications')->groupBy('payable_id')->selectRaw('payable_id, SUM(amount_minor) as credit');
        $query = DB::table('ap_payables as p')->leftJoinSub($settled, 'x', 'x.payable_id', '=', 'p.id')->leftJoinSub($credited, 'y', 'y.payable_id', '=', 'p.id')->where('p.property_id', $property->toString())
            ->whereRaw('(p.amount_minor - COALESCE(x.paid, 0) - COALESCE(y.credit, 0)) > 0');
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('p.due_date')->orderBy('p.document_number')->limit($limit)
            ->get(['p.id', 'p.supplier_name', 'p.document_number', 'p.due_date', DB::raw('(p.amount_minor - COALESCE(x.paid, 0) - COALESCE(y.credit, 0)) as owed')])
            ->map(static fn ($r): array => ['supplier' => (string) $r->supplier_name, 'document' => (string) $r->document_number, 'due' => substr((string) $r->due_date, 0, 10), 'owed' => (int) $r->owed, 'href' => '/finance/payables?status=open'])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function supplierPayments(PropertyId $property, ReportPeriod $period, int $limit): array
    {
        $query = DB::table('ap_payments as m')->join('ap_payables as p', 'p.id', '=', 'm.payable_id')->where('m.property_id', $property->toString())->whereIn('m.status', ['paid', 'reversal'])
            ->whereBetween('m.paid_on', [$period->from->toString(), $period->to->toString()]);
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('m.paid_on')->orderBy('m.number')->limit($limit)
            ->get(['m.number', 'm.status', 'm.paid_on', 'm.method', 'm.amount_minor', 'p.supplier_name', 'p.document_number'])
            ->map(static fn ($r): array => ['payment' => (string) $r->number, 'supplier' => (string) $r->supplier_name, 'document' => (string) $r->document_number, 'paid_on' => substr((string) $r->paid_on, 0, 10),
                'method' => (string) $r->method, 'paid' => $r->status === 'reversal' ? -(int) $r->amount_minor : (int) $r->amount_minor, 'href' => '/finance/payables'])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function lowStock(PropertyId $property, ?array $departments, int $limit): array
    {
        $pid = $property->toString();
        $moved = DB::table('stock_movements')->where('property_id', $pid)->groupBy('item_id', 'location_id')->selectRaw('item_id, location_id, SUM(base_qty_milli) as balance');
        $query = DB::table('inventory_stock_limits as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')->join('inventory_locations as loc', 'loc.id', '=', 'l.location_id')
            ->leftJoinSub($moved, 'm', fn ($j) => $j->on('m.item_id', '=', 'l.item_id')->on('m.location_id', '=', 'l.location_id'))
            ->where('l.property_id', $pid)->where('i.is_active', true)->where('loc.is_active', true)->where('l.min_milli', '>', 0)->whereRaw('COALESCE(m.balance, 0) < l.min_milli')
            ->when($departments !== null, static fn ($q) => $q->whereIn('i.department', $departments));
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('i.department')->orderBy('i.code')->orderBy('loc.code')->limit($limit)
            ->get(['i.department', 'i.code as item', 'i.name', 'loc.code as location', DB::raw('COALESCE(m.balance, 0) as balance'), 'l.min_milli'])
            ->map(static fn ($r): array => ['department' => (string) $r->department, 'item' => (string) $r->item, 'name' => (string) $r->name, 'location' => (string) $r->location,
                'balance' => (int) $r->balance, 'minimum' => (int) $r->min_milli, 'href' => '/inventory/stock'])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function workOrders(PropertyId $property, string $set, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, DateTimeImmutable $nowUtc, int $limit): array
    {
        $query = DB::table('maintenance_work_orders as w')->where('w.property_id', $property->toString());
        $format = 'Y-m-d H:i:s.u';

        if ($set === 'done_today') {
            $query->where('w.status', 'done')->where('w.done_at', '>=', $fromUtc->format($format))->where('w.done_at', '<', $toUtc->format($format));
        } else {
            $query->whereIn('w.status', ['open', 'assigned', 'in_progress', 'on_hold']);

            if ($set === 'overdue') {
                $query->where('w.due_at', '<', $nowUtc->format($format));
            }
        }

        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('w.due_at')->orderBy('w.number')->limit($limit)->get(['w.id', 'w.number', 'w.title', 'w.status', 'w.priority', 'w.room_number', 'w.due_at'])
            ->map(static fn ($r): array => ['number' => (string) $r->number, 'title' => (string) $r->title, 'status' => (string) $r->status, 'priority' => (string) $r->priority, 'room' => $r->room_number === null ? null : (string) $r->room_number,
                'due' => $r->due_at === null ? null : substr((string) $r->due_at, 0, 16), 'href' => '/maintenance/work-orders/'.$r->id])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function settledBills(PropertyId $property, ReportPeriod $period, ?array $outletIds, int $limit): array
    {
        $query = DB::table('fnb_bills as b')->join('fnb_outlets as o', 'o.id', '=', 'b.outlet_id')->where('b.property_id', $property->toString())->where('b.status', 'settled')
            ->whereBetween('b.business_date', [$period->from->toString(), $period->to->toString()])->when($outletIds !== null, static fn ($q) => $q->whereIn('b.outlet_id', $outletIds));
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('b.business_date')->orderBy('b.closed_at')->orderBy('b.number')->limit($limit)->get(['b.id', 'b.number', 'o.code as outlet', 'b.business_date', 'b.closed_at', 'b.covers', 'b.total_minor'])
            ->map(static fn ($r): array => ['bill' => (string) $r->number, 'outlet' => (string) $r->outlet, 'date' => substr((string) $r->business_date, 0, 10), 'closed_at' => $r->closed_at === null ? null : (string) $r->closed_at,
                'covers' => (int) $r->covers, 'total' => (int) $r->total_minor, 'href' => '/fnb/bills/'.$r->id])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function soldLines(PropertyId $property, ReportPeriod $period, int $limit): array
    {
        $query = DB::table('fnb_bill_lines as l')->join('fnb_bills as b', 'b.id', '=', 'l.bill_id')->join('fnb_outlets as o', 'o.id', '=', 'b.outlet_id')->where('b.property_id', $property->toString())->where('b.status', 'settled')
            ->whereBetween('b.business_date', [$period->from->toString(), $period->to->toString()])->whereNotIn('l.status', ['voided', 'removed']);
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy('b.business_date')->orderBy('b.number')->orderBy('l.line_no')->limit($limit)->get(['b.id as bill_id', 'b.number', 'o.code as outlet', 'b.business_date', 'l.item_code', 'l.item_name', 'l.quantity', 'l.line_total_minor'])
            ->map(static fn ($r): array => ['bill' => (string) $r->number, 'outlet' => (string) $r->outlet, 'date' => substr((string) $r->business_date, 0, 10), 'item' => (string) $r->item_code, 'name' => (string) $r->item_name,
                'quantity' => (int) $r->quantity, 'total' => (int) $r->line_total_minor, 'href' => '/fnb/bills/'.$r->bill_id])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    private function stayQuery(PropertyId $property): Builder
    {
        return DB::table('stays as s')->join('reservations as r', 'r.id', '=', 's.reservation_id')->join('rooms', 'rooms.id', '=', 's.room_id')->where('s.property_id', $property->toString());
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    private function stays(Builder $query, string $order, int $limit): array
    {
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy($order)->orderBy('s.id')->limit($limit)->get(self::STAYS)
            ->map(static fn ($r): array => ['reservation' => (string) $r->reservation, 'room' => (string) $r->room, 'checked_in' => substr((string) $r->checked_in_business_date, 0, 10), 'expected_departure' => substr((string) $r->expected_departure, 0, 10),
                'checked_out' => $r->checked_out_business_date === null ? null : substr((string) $r->checked_out_business_date, 0, 10), 'adults' => (int) $r->adults, 'children' => (int) $r->children, 'href' => '/front-office/stays/'.$r->id])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    private function reservations(Builder $query, string $order, int $limit): array
    {
        $total = (int) (clone $query)->count();
        $rows = $query->orderBy($order)->limit($limit)->get(['r.id', 'r.number', 'r.status', 'r.arrival_date', 'r.departure_date', 'r.created_at'])
            ->map(static fn ($r): array => ['reservation' => (string) $r->number, 'status' => (string) $r->status, 'arrival' => substr((string) $r->arrival_date, 0, 10), 'departure' => substr((string) $r->departure_date, 0, 10),
                'made_at' => (string) $r->created_at, 'href' => '/front-office/reservations/'.$r->id])->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, string> revenue outlet code by posting source */
    private function outletOfSource(string $propertyId): array
    {
        $byId = DB::table('revenue_outlets')->where('property_id', $propertyId)->pluck('code', 'id')->all();
        $out = [];

        foreach (DB::table('revenue_outlet_sources')->where('property_id', $propertyId)->get(['source', 'outlet_id']) as $row) {
            $out[(string) $row->source] = (string) ($byId[$row->outlet_id] ?? '');
        }

        return $out;
    }
}
