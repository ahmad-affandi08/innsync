<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Inventory;

use App\Modules\FrontOffice\Application\Inventory\RoomBlock;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseRoomBlockRepository implements RoomBlockRepository
{
    public function add(PropertyId $property, RoomBlock $block, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('room_blocks')->insert([
            'id' => $block->id, 'property_id' => $property->toString(), 'room_id' => $block->roomId, 'kind' => $block->kind,
            'start_date' => $block->from, 'end_date' => $block->to, 'reason' => $block->reason, 'created_by' => $actorId, 'created_at' => $at,
        ]);
    }

    public function find(PropertyId $property, string $id): ?RoomBlock
    {
        $row = DB::table('room_blocks')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function release(PropertyId $property, string $id, string $actorId, string $reason, DateTimeImmutable $at): bool
    {
        return DB::table('room_blocks')->where('property_id', $property->toString())->where('id', $id)->whereNull('released_at')
            ->update(['released_at' => $at, 'released_by' => $actorId, 'release_reason' => $reason]) === 1;
    }

    public function active(PropertyId $property): array
    {
        return DB::table('room_blocks')->where('property_id', $property->toString())->whereNull('released_at')->orderBy('start_date')->get()
            ->map(static fn (stdClass $r): RoomBlock => self::hydrate($r))->all();
    }

    public function overlapping(PropertyId $property, string $roomId, string $from, string $to): array
    {
        return DB::table('room_blocks')->where('property_id', $property->toString())->where('room_id', $roomId)->whereNull('released_at')
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get()
            ->map(static fn (stdClass $r): RoomBlock => self::hydrate($r))->all();
    }

    public function blockedRooms(PropertyId $property, string $from, string $to): array
    {
        $blocked = [];

        foreach (DB::table('room_blocks')->where('property_id', $property->toString())->whereNull('released_at')->where('start_date', '<=', $to)->where('end_date', '>=', $from)->distinct()->pluck('room_id') as $roomId) {
            $blocked[(string) $roomId] = true;
        }

        return $blocked;
    }

    private static function hydrate(stdClass $r): RoomBlock
    {
        return new RoomBlock($r->id, $r->room_id, $r->kind, substr((string) $r->start_date, 0, 10), substr((string) $r->end_date, 0, 10), $r->reason, $r->released_at === null);
    }
}
