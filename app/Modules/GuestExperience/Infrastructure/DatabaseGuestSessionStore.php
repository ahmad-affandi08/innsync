<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\GuestSessionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGuestSessionStore implements GuestSessionStore
{
    public function add(PropertyId $property, array $row): void
    {
        DB::table('ge_sessions')->insert([...$row, 'property_id' => $property->toString()]);
    }

    public function byTokenHash(string $hash): ?array
    {
        $r = DB::table('ge_sessions as s')->join('ge_qr_points as q', 'q.id', '=', 's.qr_point_id')->where('s.token_hash', $hash)
            ->first(['s.*', 'q.kind as point_kind', 'q.target_id as point_target', 'q.label as point_label', 'q.is_active as point_active']);

        return $r === null ? null : (array) $r;
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('ge_sessions')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function update(PropertyId $property, string $id, array $fields): void
    {
        DB::table('ge_sessions')->where('property_id', $property->toString())->where('id', $id)->update($fields);
    }

    public function endOfCode(PropertyId $property, string $qrPointId, DateTimeImmutable $at): void
    {
        DB::table('ge_sessions')->where('property_id', $property->toString())->where('qr_point_id', $qrPointId)->where('expires_at', '>', $at)->update(['expires_at' => $at]);
    }
}
