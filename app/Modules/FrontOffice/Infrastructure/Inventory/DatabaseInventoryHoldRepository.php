<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Inventory;

use App\Modules\FrontOffice\Application\Inventory\InventoryHold;
use App\Modules\FrontOffice\Application\Inventory\InventoryHoldRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseInventoryHoldRepository implements InventoryHoldRepository
{
    public function add(PropertyId $property, InventoryHold $hold, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('inventory_holds')->insert([
            'id' => $hold->id, 'property_id' => $property->toString(), 'room_type_id' => $hold->roomTypeId, 'start_date' => $hold->from, 'end_date' => $hold->to,
            'rooms' => $hold->rooms, 'reason' => $hold->reason, 'expires_at' => $hold->expiresAt === null ? null : CarbonImmutable::parse($hold->expiresAt, 'UTC'),
            'created_by' => $actorId, 'created_at' => $at,
        ]);
    }

    public function find(PropertyId $property, string $id): ?InventoryHold
    {
        $row = DB::table('inventory_holds')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function release(PropertyId $property, string $id, string $actorId, string $reason, DateTimeImmutable $at): bool
    {
        return DB::table('inventory_holds')->where('property_id', $property->toString())->where('id', $id)->whereNull('released_at')
            ->update(['released_at' => $at, 'released_by' => $actorId, 'release_reason' => $reason]) === 1;
    }

    public function active(PropertyId $property, DateTimeImmutable $now): array
    {
        return DB::table('inventory_holds')->where('property_id', $property->toString())->whereNull('released_at')
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now->format('Y-m-d H:i:s.u')))
            ->orderBy('start_date')->get()->map(static fn (stdClass $r): InventoryHold => self::hydrate($r))->all();
    }

    private static function hydrate(stdClass $r): InventoryHold
    {
        return new InventoryHold(
            $r->id, $r->room_type_id, substr((string) $r->start_date, 0, 10), substr((string) $r->end_date, 0, 10), (int) $r->rooms, $r->reason,
            $r->expires_at === null ? null : CarbonImmutable::parse($r->expires_at, 'UTC')->format('Y-m-d\TH:i:s\Z'), $r->released_at === null,
        );
    }
}
