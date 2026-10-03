<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\AnnouncementStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseAnnouncementStore implements AnnouncementStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_announcements')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_announcements')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_announcements')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function list(PropertyId $property, bool $withWithdrawn, int $limit): array
    {
        return DB::table('hr_announcements')->where('property_id', $property->toString())->when(! $withWithdrawn, static fn ($q) => $q->where('status', 'published'))->orderByDesc('published_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function reads(PropertyId $property, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('hr_announcement_reads')->where('property_id', $property->toString())->whereIn('announcement_id', $ids)->get() as $r) {
            $out[(string) $r->announcement_id][(string) $r->employee_id] = ['read_at' => (string) $r->read_at, 'acknowledged_at' => $r->acknowledged_at === null ? null : (string) $r->acknowledged_at];
        }

        return $out;
    }

    public function markRead(PropertyId $property, string $announcementId, string $employeeId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_announcement_reads')->insert(['announcement_id' => $announcementId, 'employee_id' => $employeeId, 'property_id' => $property->toString(), 'read_at' => $at->format('Y-m-d H:i:s.u')]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function acknowledge(PropertyId $property, string $announcementId, string $employeeId, DateTimeImmutable $at): bool
    {
        return DB::table('hr_announcement_reads')->where('property_id', $property->toString())->where('announcement_id', $announcementId)->where('employee_id', $employeeId)->whereNull('acknowledged_at')->update(['acknowledged_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }
}
