<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\DoorLocks;

use App\Modules\FrontOffice\Application\DoorLocks\DoorLockStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDoorLockStore implements DoorLockStore
{
    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('door_lock_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : ['enabled' => (bool) $r->enabled, 'vendor' => (string) $r->vendor, 'key_count' => (int) $r->key_count, 'token_hint' => $r->token_hint === null ? null : (string) $r->token_hint, 'lock_version' => (int) $r->lock_version];
    }

    public function saveSettings(PropertyId $property, bool $enabled, string $vendor, int $keyCount, ?int $lock, string $by, DateTimeImmutable $at): bool
    {
        $row = ['enabled' => $enabled, 'vendor' => $vendor, 'key_count' => $keyCount, 'updated_by' => $by, 'updated_at' => $at];

        if ($lock !== null) {
            return DB::table('door_lock_settings')->where('property_id', $property->toString())->where('lock_version', $lock)->update([...$row, 'lock_version' => $lock + 1]) === 1;
        }

        try {
            return DB::table('door_lock_settings')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function saveToken(PropertyId $property, string $hash, string $hint, string $by, DateTimeImmutable $at): void
    {
        DB::table('door_lock_settings')->where('property_id', $property->toString())->update(['token_hash' => $hash, 'token_hint' => $hint, 'updated_by' => $by, 'updated_at' => $at]);
    }

    public function propertyOfToken(string $propertyUlid, string $hash): ?PropertyId
    {
        $found = DB::table('door_lock_settings')->where('property_id', $propertyUlid)->value('token_hash');

        return is_string($found) && hash_equals($found, $hash) ? PropertyId::fromString($propertyUlid) : null;
    }

    public function addJob(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('door_lock_jobs')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'pending', 'attempts' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function jobs(PropertyId $property, int $limit): array
    {
        return DB::table('door_lock_jobs')->where('property_id', $property->toString())->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function job(PropertyId $property, string $id): ?array
    {
        $r = DB::table('door_lock_jobs')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function counts(PropertyId $property): array
    {
        return DB::table('door_lock_jobs')->where('property_id', $property->toString())->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all();
    }

    public function claim(PropertyId $property, int $limit, int $leaseSeconds, DateTimeImmutable $now): array
    {
        $stale = $now->modify('-'.$leaseSeconds.' seconds');
        $rows = DB::table('door_lock_jobs')->where('property_id', $property->toString())
            ->where(static fn ($q) => $q->where('status', 'pending')->orWhere(static fn ($q2) => $q2->where('status', 'sent')->where('claimed_at', '<', $stale)))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->lockForUpdate()->get();
        $out = [];

        foreach ($rows as $r) {
            DB::table('door_lock_jobs')->where('id', $r->id)->update(['status' => 'sent', 'attempts' => (int) $r->attempts + 1, 'claimed_at' => $now, 'updated_at' => $now]);
            $out[] = (array) $r;
        }

        return $out;
    }

    public function finish(PropertyId $property, string $id, string $status, ?string $message, ?string $by, DateTimeImmutable $now): bool
    {
        return DB::table('door_lock_jobs')->where('property_id', $property->toString())->where('id', $id)->whereNotIn('status', ['done', 'manual'])
            ->update(['status' => $status, 'message' => $message, 'finished_at' => $now, 'settled_by' => $by, 'updated_at' => $now]) === 1;
    }

    public function requeue(PropertyId $property, string $id, DateTimeImmutable $now): bool
    {
        return DB::table('door_lock_jobs')->where('property_id', $property->toString())->where('id', $id)->whereIn('status', ['failed', 'sent'])->update(['status' => 'pending', 'message' => null, 'claimed_at' => null, 'updated_at' => $now]) === 1;
    }
}
