<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\AttendanceStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseAttendanceStore implements AttendanceStore
{
    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('hr_attendance_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : (array) $r;
    }

    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $row = [
            'latitude' => $values['latitude'], 'longitude' => $values['longitude'], 'radius_m' => $values['radius_m'], 'require_selfie' => $values['require_selfie'],
            'late_grace_minutes' => $values['late_grace'], 'early_grace_minutes' => $values['early_grace'], 'extra_after_minutes' => $values['extra_after'],
        ];
        $stamp = $at->format('Y-m-d H:i:s.u');

        if ($expectedLock === null) {
            try {
                DB::table('hr_attendance_settings')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $stamp, 'updated_at' => $stamp]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('hr_attendance_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update([...$row, 'lock_version' => $expectedLock + 1, 'updated_by' => $by, 'updated_at' => $stamp]) === 1;
    }

    public function record(PropertyId $property, string $employeeId, string $date): ?array
    {
        $r = DB::table('hr_attendance')->where('property_id', $property->toString())->where('employee_id', $employeeId)->where('work_date', $date)->first();

        return $r === null ? null : (array) $r;
    }

    public function recordById(PropertyId $property, string $id): ?array
    {
        $r = DB::table('hr_attendance')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('hr_attendance')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_attendance')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function between(PropertyId $property, string $from, string $to, ?string $employeeId): array
    {
        return DB::table('hr_attendance')->where('property_id', $property->toString())->whereBetween('work_date', [$from, $to])->when($employeeId !== null, static fn ($q) => $q->where('employee_id', $employeeId))->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function reviews(PropertyId $property, array $attendanceIds): array
    {
        if ($attendanceIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('hr_attendance_reviews')->where('property_id', $property->toString())->whereIn('attendance_id', $attendanceIds)->get() as $r) {
            $out[$r->attendance_id.':'.$r->side] = ['decision' => (string) $r->decision, 'reviewed_by' => (string) $r->reviewed_by, 'reviewed_at' => (string) $r->reviewed_at, 'note' => $r->note === null ? null : (string) $r->note];
        }

        return $out;
    }

    public function addReview(PropertyId $property, string $id, string $attendanceId, string $side, array $flags, string $decision, ?string $note, string $by, DateTimeImmutable $at): bool
    {
        try {
            DB::table('hr_attendance_reviews')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'attendance_id' => $attendanceId, 'side' => $side, 'flags' => json_encode($flags, JSON_THROW_ON_ERROR),
                'decision' => $decision, 'note' => $note, 'reviewed_by' => $by, 'reviewed_at' => $at->format('Y-m-d H:i:s.u'),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
