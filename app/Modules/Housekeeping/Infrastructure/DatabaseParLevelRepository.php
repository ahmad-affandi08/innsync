<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Infrastructure;

use App\Modules\Housekeeping\Application\ParLevelRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseParLevelRepository implements ParLevelRepository
{
    public function all(PropertyId $property): array
    {
        return DB::table('linen_par_levels as p')->join('linen_items as i', 'i.id', '=', 'p.item_id')->where('p.property_id', $property->toString())
            ->orderBy('i.code')->orderBy('p.scope_kind')->orderBy('p.scope_key')->get(['p.*', 'i.code as item_code', 'i.name as item_name', 'i.kind as item_kind'])
            ->map(static fn ($r): array => self::shape($r))->all();
    }

    public function find(PropertyId $property, string $itemId, string $scopeKind, string $scopeRef): ?array
    {
        $row = DB::table('linen_par_levels as p')->join('linen_items as i', 'i.id', '=', 'p.item_id')->where('p.property_id', $property->toString())->where('p.item_id', $itemId)->where('p.scope_kind', $scopeKind)
            ->where('p.scope_key', $scopeKind === 'area' ? 'area:'.$scopeRef : $scopeRef)->first(['p.*', 'i.code as item_code', 'i.name as item_name', 'i.kind as item_kind']);

        return $row === null ? null : self::shape($row);
    }

    public function save(PropertyId $property, string $id, string $itemId, string $scopeKind, string $scopeRef, int $par, int $use, ?int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool
    {
        $pid = $property->toString();
        $key = $scopeKind === 'area' ? 'area:'.$scopeRef : $scopeRef;

        if ($expectedLockVersion === null) {
            return DB::table('linen_par_levels')->insertOrIgnore([
                'id' => $id, 'property_id' => $pid, 'item_id' => $itemId, 'scope_kind' => $scopeKind, 'room_type_id' => $scopeKind === 'room_type' ? $scopeRef : null, 'area' => $scopeKind === 'area' ? $scopeRef : null,
                'par_quantity' => $par, 'use_quantity' => $use, 'lock_version' => 0, 'updated_by' => $actorId, 'created_at' => $at, 'updated_at' => $at,
            ]) === 1;
        }

        return DB::table('linen_par_levels')->where('property_id', $pid)->where('item_id', $itemId)->where('scope_kind', $scopeKind)->where('scope_key', $key)->where('lock_version', $expectedLockVersion)
            ->update(['par_quantity' => $par, 'use_quantity' => $use, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $actorId, 'updated_at' => $at]) === 1;
    }

    public function areas(PropertyId $property): array
    {
        return DB::table('linen_par_levels')->where('property_id', $property->toString())->where('scope_kind', 'area')->distinct()->orderBy('area')->pluck('area')->all();
    }

    public function servicedRooms(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        return DB::table('housekeeping_tasks as t')->join('rooms as r', 'r.id', '=', 't.room_id')->where('t.property_id', $property->toString())->where('t.status', 'done')
            ->where('t.finished_at', '>=', $fromUtc->format('Y-m-d H:i:s.u'))->where('t.finished_at', '<', $toUtc->format('Y-m-d H:i:s.u'))
            ->groupBy('r.room_type_id')->get(['r.room_type_id', DB::raw('COUNT(*) as n')])->pluck('n', 'room_type_id')->map(static fn ($n): int => (int) $n)->all();
    }

    public function usedIn(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $result = [];

        foreach (DB::table('linen_usage as u')->join('rooms as r', 'r.id', '=', 'u.room_id')->where('u.property_id', $property->toString())
            ->where('u.recorded_at', '>=', $fromUtc->format('Y-m-d H:i:s.u'))->where('u.recorded_at', '<', $toUtc->format('Y-m-d H:i:s.u'))
            ->groupBy('u.item_id', 'r.room_type_id')->get(['u.item_id', 'r.room_type_id', DB::raw('SUM(u.quantity) as n')]) as $row) {
            $result[$row->item_id][$row->room_type_id] = (int) $row->n;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return [
            'id' => $r->id, 'item_id' => $r->item_id, 'item_code' => $r->item_code, 'item_name' => $r->item_name, 'item_kind' => $r->item_kind, 'scope_kind' => $r->scope_kind,
            'room_type_id' => $r->room_type_id, 'area' => $r->area, 'par_quantity' => (int) $r->par_quantity, 'use_quantity' => (int) $r->use_quantity, 'lock_version' => (int) $r->lock_version,
        ];
    }
}
