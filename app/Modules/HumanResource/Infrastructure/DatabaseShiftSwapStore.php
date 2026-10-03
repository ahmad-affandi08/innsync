<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\ShiftSwapStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseShiftSwapStore implements ShiftSwapStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('hr_shift_swaps')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = $this->joined()->where('s.property_id', $property->toString())->where('s.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('hr_shift_swaps')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function forPerson(PropertyId $property, string $employeeId, int $limit): array
    {
        return $this->joined()->where('s.property_id', $property->toString())->where(static fn ($q) => $q->where('s.requester_id', $employeeId)->orWhere('s.partner_id', $employeeId))
            ->orderByDesc('s.work_date')->orderByDesc('s.created_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function awaitingSupervisor(PropertyId $property, int $limit): array
    {
        return $this->joined()->where('s.property_id', $property->toString())->where('s.status', 'awaiting_supervisor')->orderBy('s.work_date')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function openOn(PropertyId $property, string $employeeId, string $date): bool
    {
        return DB::table('hr_shift_swaps')->where('property_id', $property->toString())->where('work_date', $date)->whereIn('status', ['awaiting_partner', 'awaiting_supervisor'])
            ->where(static fn ($q) => $q->where('requester_id', $employeeId)->orWhere('partner_id', $employeeId))->exists();
    }

    private function joined(): Builder
    {
        return DB::table('hr_shift_swaps as s')->join('hr_employees as a', 'a.id', '=', 's.requester_id')->join('hr_employees as b', 'b.id', '=', 's.partner_id')
            ->select('s.*', 'a.number as requester_number', 'a.full_name as requester_name', 'a.department as department', 'a.user_id as requester_user', 'a.supervisor_id as requester_supervisor', 'b.number as partner_number', 'b.full_name as partner_name', 'b.user_id as partner_user', 'b.supervisor_id as partner_supervisor');
    }
}
