<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Requests;

use App\Modules\FrontOffice\Application\Requests\GuestRequestRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGuestRequestRepository implements GuestRequestRepository
{
    public function add(PropertyId $property, string $id, string $number, string $stayId, string $reservationId, string $roomId, string $category, string $priority, string $title, ?string $detail, ?string $hkTaskId, ?string $clientKey, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('guest_requests')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'number' => $number, 'stay_id' => $stayId, 'reservation_id' => $reservationId, 'room_id' => $roomId, 'category' => $category,
            'priority' => $priority, 'title' => $title, 'detail' => $detail, 'status' => 'open', 'hk_task_id' => $hkTaskId, 'client_key' => $clientKey, 'created_by' => $actorId, 'created_at' => $at, 'lock_version' => 0, 'updated_at' => $at,
        ]);
    }

    public function findByKey(PropertyId $property, string $clientKey): ?array
    {
        $row = DB::table('guest_requests as g')->join('rooms', 'rooms.id', '=', 'g.room_id')->where('g.property_id', $property->toString())->where('g.client_key', $clientKey)->first(['g.*', 'rooms.number as room_number']);

        return $row === null ? null : self::shape($row);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('guest_requests as g')->join('rooms', 'rooms.id', '=', 'g.room_id')->where('g.property_id', $property->toString())->where('g.id', $id)->first(['g.*', 'rooms.number as room_number']);

        return $row === null ? null : self::shape($row);
    }

    public function search(PropertyId $property, array $filters, int $limit): array
    {
        $query = DB::table('guest_requests as g')->join('rooms', 'rooms.id', '=', 'g.room_id')->where('g.property_id', $property->toString());

        foreach (['status', 'category'] as $key) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $query->where('g.'.$key, $filters[$key]);
            }
        }

        if (($filters['room_id'] ?? null) !== null) {
            $query->where('g.room_id', $filters['room_id']);
        }

        if (($filters['stay_id'] ?? null) !== null) {
            $query->where('g.stay_id', $filters['stay_id']);
        }

        if ($filters['open_only'] ?? false) {
            $query->whereIn('g.status', ['open', 'in_progress']);
        }

        return $query->orderByRaw("CASE WHEN g.priority = 'urgent' THEN 0 ELSE 1 END")->orderBy('g.created_at')->orderBy('g.id')->limit($limit)
            ->get(['g.*', 'rooms.number as room_number'])->map(static fn ($r): array => self::shape($r))->all();
    }

    public function transition(PropertyId $property, string $id, int $expectedLockVersion, string $status, ?string $resolution, string $actorId, DateTimeImmutable $at): bool
    {
        $closed = in_array($status, ['done', 'cancelled'], true);

        return DB::table('guest_requests')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)->whereIn('status', ['open', 'in_progress'])->update([
            'status' => $status, 'resolution' => $resolution, 'closed_at' => $closed ? $at : null, 'closed_by' => $closed ? $actorId : null, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
        ]) === 1;
    }

    public function openCountsByRoom(PropertyId $property): array
    {
        return DB::table('guest_requests')->where('property_id', $property->toString())->whereIn('status', ['open', 'in_progress'])->groupBy('room_id')->pluck(DB::raw('COUNT(*)'), 'room_id')->map(static fn ($n): int => (int) $n)->all();
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r->id, 'number' => $r->number, 'stay_id' => $r->stay_id, 'reservation_id' => $r->reservation_id, 'room_id' => $r->room_id, 'room' => $r->room_number,
            'category' => $r->category, 'priority' => $r->priority, 'title' => $r->title, 'detail' => $r->detail, 'status' => $r->status, 'hk_task_id' => $r->hk_task_id, 'resolution' => $r->resolution,
            'created_by' => $r->created_by, 'created_at' => $utc($r->created_at), 'closed_at' => $utc($r->closed_at), 'closed_by' => $r->closed_by, 'lock_version' => (int) $r->lock_version,
        ];
    }
}
