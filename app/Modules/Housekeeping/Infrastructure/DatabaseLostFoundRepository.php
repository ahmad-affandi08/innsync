<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Infrastructure;

use App\Modules\Housekeeping\Application\LostFoundRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseLostFoundRepository implements LostFoundRepository
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('lost_found_items')->insert([...$row, 'property_id' => $property->toString(), 'found_at' => $at, 'status' => 'stored', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = $this->query($property)->where('i.id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function list(PropertyId $property, ?string $status, int $limit): array
    {
        $query = $this->query($property);

        if ($status !== null) {
            $query->where('i.status', $status);
        }

        return $query->orderByDesc('i.found_business_date')->orderByDesc('i.number')->limit($limit)->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function close(PropertyId $property, string $id, int $expectedLockVersion, string $status, ?string $returnedTo, ?string $note, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('lost_found_items')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)->where('status', 'stored')
            ->update(['status' => $status, 'returned_to' => $returnedTo, 'closed_note' => $note, 'closed_by' => $actorId, 'closed_at' => $at, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    private function query(PropertyId $property): Builder
    {
        return DB::table('lost_found_items as i')->leftJoin('rooms', 'rooms.id', '=', 'i.room_id')->where('i.property_id', $property->toString())->select('i.*', 'rooms.number as room_number');
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r->id, 'number' => $r->number, 'description' => $r->description, 'room_id' => $r->room_id, 'room' => $r->room_number, 'place' => $r->place,
            'found_date' => substr((string) $r->found_business_date, 0, 10), 'found_by' => $r->found_by, 'found_at' => $utc($r->found_at), 'photo_file_id' => $r->photo_file_id,
            'stored_at' => $r->stored_at, 'status' => $r->status, 'returned_to' => $r->returned_to, 'closed_note' => $r->closed_note, 'closed_by' => $r->closed_by, 'closed_at' => $utc($r->closed_at), 'lock_version' => (int) $r->lock_version,
        ];
    }
}
