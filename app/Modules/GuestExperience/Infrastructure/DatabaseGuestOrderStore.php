<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\GuestOrderStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGuestOrderStore implements GuestOrderStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('ge_orders')->insert([...$row, 'line_ids' => json_encode($row['line_ids'], JSON_THROW_ON_ERROR), 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('ge_orders')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : $this->shape((array) $r);
    }

    public function ofBill(PropertyId $property, string $billId): array
    {
        return array_map($this->shape(...), DB::table('ge_orders')->where('property_id', $property->toString())->where('bill_id', $billId)->orderBy('created_at')->get()->map(static fn (object $r): array => (array) $r)->all());
    }

    public function byClientKey(PropertyId $property, string $sessionId, string $clientKey): ?array
    {
        $r = DB::table('ge_orders')->where('property_id', $property->toString())->where('session_id', $sessionId)->where('client_key', $clientKey)->first();

        return $r === null ? null : $this->shape((array) $r);
    }

    public function ofSession(PropertyId $property, string $sessionId, int $limit): array
    {
        return array_map($this->shape(...), DB::table('ge_orders')->where('property_id', $property->toString())->where('session_id', $sessionId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all());
    }

    public function countSince(PropertyId $property, string $sessionId, DateTimeImmutable $since): int
    {
        return DB::table('ge_orders')->where('property_id', $property->toString())->where('session_id', $sessionId)->where('created_at', '>=', $since)->count();
    }

    public function recent(PropertyId $property, DateTimeImmutable $since, int $limit): array
    {
        $rows = DB::table('ge_orders as o')->join('ge_sessions as s', 's.id', '=', 'o.session_id')->join('ge_qr_points as q', 'q.id', '=', 'o.qr_point_id')
            ->where('o.property_id', $property->toString())->where('o.created_at', '>=', $since)->orderByDesc('o.created_at')->orderByDesc('o.id')->limit($limit)
            ->get(['o.*', 'q.label as point_label', 's.guest_name as guest_name', 's.stay_id as stay_id', 's.room_number as room_number'])->map(static fn (object $r): array => (array) $r)->all();

        return array_map($this->shape(...), $rows);
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('ge_orders')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $row['line_ids'] = json_decode((string) $row['line_ids'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }
}
