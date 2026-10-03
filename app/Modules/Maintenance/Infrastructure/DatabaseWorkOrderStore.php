<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\WorkOrderStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseWorkOrderStore implements WorkOrderStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_work_orders')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'open', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('maintenance_work_orders')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('maintenance_work_orders')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('maintenance_work_orders')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function list(PropertyId $property, ?array $statuses, ?string $involving, int $limit): array
    {
        $q = DB::table('maintenance_work_orders')->where('property_id', $property->toString());

        if ($statuses !== null) {
            $q->whereIn('status', $statuses);
        }

        if ($involving !== null) {
            $q->where(static fn ($w) => $w->where('reported_by', $involving)->orWhere('assigned_to', $involving));
        }

        return $q->orderByDesc('reported_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function addEvent(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_work_events')->insert([...$row, 'at' => $at]);
    }

    public function events(PropertyId $property, string $id): array
    {
        return DB::table('maintenance_work_events as e')->join('maintenance_work_orders as w', 'w.id', '=', 'e.work_order_id')->where('w.property_id', $property->toString())->where('e.work_order_id', $id)
            ->orderBy('e.at')->orderBy('e.id')->get(['e.*'])->map(static fn (object $r): array => (array) $r)->all();
    }

    public function settings(PropertyId $property): ?array
    {
        $r = DB::table('maintenance_settings')->where('property_id', $property->toString())->first();

        return $r === null ? null : ['urgent' => (int) $r->sla_urgent_minutes, 'high' => (int) $r->sla_high_minutes, 'normal' => (int) $r->sla_normal_minutes, 'low' => (int) $r->sla_low_minutes, 'lock_version' => (int) $r->lock_version];
    }

    public function saveSettings(PropertyId $property, array $minutes, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $values = ['sla_urgent_minutes' => $minutes['urgent'], 'sla_high_minutes' => $minutes['high'], 'sla_normal_minutes' => $minutes['normal'], 'sla_low_minutes' => $minutes['low']];

        if ($expectedLock === null) {
            try {
                DB::table('maintenance_settings')->insert([...$values, 'property_id' => $property->toString(), 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]);

                return true;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return false;
                }

                throw $e;
            }
        }

        return DB::table('maintenance_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update([...$values, 'lock_version' => $expectedLock + 1, 'updated_by' => $by, 'updated_at' => $at]) === 1;
    }
}
