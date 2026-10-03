<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Infrastructure;

use App\Modules\GuestExperience\Application\SelfCheckInStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseSelfCheckInStore implements SelfCheckInStore
{
    public function latestNotice(PropertyId $property): ?array
    {
        $r = DB::table('ge_privacy_notices')->where('property_id', $property->toString())->orderByDesc('version')->first();

        return $r === null ? null : (array) $r;
    }

    public function addNotice(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('ge_privacy_notices')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function addLink(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('ge_checkin_links')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function linkByHash(string $hash): ?array
    {
        $r = DB::table('ge_checkin_links')->where('token_hash', $hash)->first();

        return $r === null ? null : (array) $r;
    }

    public function link(PropertyId $property, string $id): ?array
    {
        $r = DB::table('ge_checkin_links')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function liveLinks(PropertyId $property, ?string $reservationId, DateTimeImmutable $at): array
    {
        $q = DB::table('ge_checkin_links')->where('property_id', $property->toString())->whereNull('revoked_at')->where('expires_at', '>', $at);
        $reservationId === null ? $q->where('kind', 'lobby') : $q->where('reservation_id', $reservationId);

        return $q->orderByDesc('created_at')->orderByDesc('id')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function updateLink(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('ge_checkin_links')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addAttempt(PropertyId $property, string $subjectHash, DateTimeImmutable $at): void
    {
        DB::table('ge_checkin_attempts')->insert(['property_id' => $property->toString(), 'subject_hash' => $subjectHash, 'created_at' => $at]);
    }

    public function attemptsSince(PropertyId $property, string $subjectHash, DateTimeImmutable $since): int
    {
        return DB::table('ge_checkin_attempts')->where('property_id', $property->toString())->where('subject_hash', $subjectHash)->where('created_at', '>=', $since)->count();
    }

    public function addCheckin(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('ge_checkins')->insert([...$row, 'property_id' => $property->toString(), 'submitted_at' => $at, 'lock_version' => 0, 'updated_at' => $at]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function checkin(PropertyId $property, string $id): ?array
    {
        $r = DB::table('ge_checkins')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function checkinOfReservation(PropertyId $property, string $reservationId): ?array
    {
        $r = DB::table('ge_checkins')->where('property_id', $property->toString())->where('reservation_id', $reservationId)
            ->orderByRaw("status = 'rejected'")->orderByDesc('submitted_at')->orderByDesc('id')->first();

        return $r === null ? null : (array) $r;
    }

    public function checkinsByStatus(PropertyId $property, string $status, int $limit): array
    {
        return DB::table('ge_checkins')->where('property_id', $property->toString())->where('status', $status)->orderBy('submitted_at')->orderBy('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function decide(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('ge_checkins')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->where('status', 'submitted')->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }
}
