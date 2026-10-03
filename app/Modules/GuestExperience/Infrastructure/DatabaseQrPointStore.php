<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\QrPointStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseQrPointStore implements QrPointStore
{
    public function all(PropertyId $property): array
    {
        return DB::table('ge_qr_points')->where('property_id', $property->toString())->orderBy('kind')->orderBy('label')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('ge_qr_points')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function byTarget(PropertyId $property, string $kind, string $targetId): ?array
    {
        $r = DB::table('ge_qr_points')->where('property_id', $property->toString())->where('kind', $kind)->where('target_id', $targetId)->first();

        return $r === null ? null : (array) $r;
    }

    public function byTokenHash(string $hash): ?array
    {
        $r = DB::table('ge_qr_points')->where('token_hash', $hash)->first();

        return $r === null ? null : (array) $r;
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('ge_qr_points')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('ge_qr_points')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }
}
