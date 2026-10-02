<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Groups;

use App\Modules\FrontOffice\Application\Groups\GroupRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGroupRepository implements GroupRepository
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('reservation_groups')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addMember(PropertyId $property, string $groupId, string $reservationId, int $line, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('reservation_group_members')->insert(['reservation_id' => $reservationId, 'group_id' => $groupId, 'property_id' => $property->toString(), 'line' => $line, 'added_by' => $actorId, 'added_at' => $at]);
    }

    public function markMasterFolio(PropertyId $property, string $groupId, string $folioId, DateTimeImmutable $at): void
    {
        DB::table('group_master_folios')->insert(['group_id' => $groupId, 'folio_id' => $folioId, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('reservation_groups')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function search(PropertyId $property, ?string $query, int $limit): array
    {
        $rows = DB::table('reservation_groups as g')->leftJoin('group_master_folios as m', 'm.group_id', '=', 'g.id')->leftJoin('folios as f', 'f.id', '=', 'm.folio_id')
            ->where('g.property_id', $property->toString())
            ->when($query !== null && $query !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('g.number', 'like', '%'.addcslashes((string) $query, '%_\\').'%')->orWhere('g.name', 'like', '%'.addcslashes((string) $query, '%_\\').'%')))
            ->orderByDesc('g.created_at')->orderByDesc('g.id')->limit($limit)
            ->get(['g.*', 'f.balance_minor as master_balance', 'f.status as master_status', DB::raw('(SELECT COUNT(*) FROM reservation_group_members x WHERE x.group_id = g.id) as rooms')]);

        return $rows->map(static fn ($r): array => [...self::shape($r), 'rooms' => (int) $r->rooms, 'master_balance_minor' => $r->master_balance === null ? null : (int) $r->master_balance, 'master_status' => $r->master_status])->all();
    }

    public function members(PropertyId $property, string $groupId): array
    {
        return DB::table('reservation_group_members as m')->join('reservations as r', 'r.id', '=', 'm.reservation_id')
            ->leftJoin('room_types as t', 't.id', '=', 'r.room_type_id')->leftJoin('rooms as rm', 'rm.id', '=', 'r.room_id')
            ->where('m.property_id', $property->toString())->where('m.group_id', $groupId)->orderBy('m.line')
            ->get(['m.line', 'r.id', 'r.number', 'r.status', 'r.guest_name', 'r.adults', 'r.children', 'r.arrival_date', 'r.departure_date', 'r.total_minor', 'r.currency_code', 't.code as type_code', 't.name as type_name', 'rm.number as room',
                DB::raw('(SELECT COALESCE(SUM(f.balance_minor), 0) FROM folios f WHERE f.reservation_id = r.id AND f.id NOT IN (SELECT folio_id FROM group_master_folios)) as own_balance')])
            ->map(static fn ($r): array => [
                'line' => (int) $r->line, 'reservation_id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'guest_name' => $r->guest_name, 'adults' => (int) $r->adults, 'children' => (int) $r->children,
                'arrival' => substr((string) $r->arrival_date, 0, 10), 'departure' => substr((string) $r->departure_date, 0, 10), 'total_minor' => (int) $r->total_minor, 'currency' => $r->currency_code,
                'room_type' => $r->type_code, 'room_type_name' => $r->type_name, 'room' => $r->room, 'own_balance_minor' => (int) $r->own_balance,
            ])->all();
    }

    public function groupOf(PropertyId $property, string $reservationId): ?array
    {
        $row = DB::table('reservation_group_members as m')->join('reservation_groups as g', 'g.id', '=', 'm.group_id')->where('m.property_id', $property->toString())->where('m.reservation_id', $reservationId)->first(['g.*']);

        return $row === null ? null : self::shape($row);
    }

    public function masterFolioId(PropertyId $property, string $groupId): ?string
    {
        $id = DB::table('group_master_folios')->where('property_id', $property->toString())->where('group_id', $groupId)->value('folio_id');

        return $id === null ? null : (string) $id;
    }

    public function isMasterFolio(PropertyId $property, string $folioId): bool
    {
        return DB::table('group_master_folios')->where('property_id', $property->toString())->where('folio_id', $folioId)->exists();
    }

    public function lastLine(PropertyId $property, string $groupId): int
    {
        return (int) DB::table('reservation_group_members')->where('property_id', $property->toString())->where('group_id', $groupId)->lockForUpdate()->max('line');
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'booker_name' => $r->booker_name, 'booker_phone' => $r->booker_phone, 'booker_email' => $r->booker_email, 'source' => $r->source,
            'arrival' => substr((string) $r->arrival_date, 0, 10), 'departure' => substr((string) $r->departure_date, 0, 10), 'billing_mode' => $r->billing_mode, 'route_extras' => (bool) $r->route_extras, 'notes' => $r->notes,
            'created_at' => substr((string) $r->created_at, 0, 19),
        ];
    }
}
