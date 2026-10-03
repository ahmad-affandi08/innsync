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

        return $r === null ? null : [
            'urgent' => (int) $r->sla_urgent_minutes, 'high' => (int) $r->sla_high_minutes, 'normal' => (int) $r->sla_normal_minutes, 'low' => (int) $r->sla_low_minutes,
            'warn' => (int) $r->warn_percent, 'escalate' => (int) $r->escalate_percent, 'night_from' => (int) $r->night_from_hour, 'night_to' => (int) $r->night_to_hour, 'lock_version' => (int) $r->lock_version,
        ];
    }

    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool
    {
        $row = [
            'sla_urgent_minutes' => $values['urgent'], 'sla_high_minutes' => $values['high'], 'sla_normal_minutes' => $values['normal'], 'sla_low_minutes' => $values['low'],
            'warn_percent' => $values['warn'], 'escalate_percent' => $values['escalate'], 'night_from_hour' => $values['night_from'], 'night_to_hour' => $values['night_to'],
        ];

        if ($expectedLock === null) {
            try {
                DB::table('maintenance_settings')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]);

                return true;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return false;
                }

                throw $e;
            }
        }

        return DB::table('maintenance_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLock)->update([...$row, 'lock_version' => $expectedLock + 1, 'updated_by' => $by, 'updated_at' => $at]) === 1;
    }

    public function addRoomBlock(PropertyId $property, array $row): void
    {
        DB::table('maintenance_room_blocks')->insert([...$row, 'property_id' => $property->toString()]);
    }

    public function releaseRoomBlock(PropertyId $property, string $blockId, string $date): void
    {
        DB::table('maintenance_room_blocks')->where('property_id', $property->toString())->where('block_id', $blockId)->whereNull('released_on')->update(['released_on' => $date]);
    }

    public function roomBlocksBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('maintenance_room_blocks')->where('property_id', $property->toString())->where('from_date', '<=', $to)->where(static fn ($q) => $q->whereNull('released_on')->orWhere('released_on', '>', $from))
            ->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function reportedBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('maintenance_work_orders')->where('property_id', $property->toString())->where('reported_at', '>=', $from.' 00:00:00')->where('reported_at', '<', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00')
            ->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function doneBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('maintenance_work_orders')->where('property_id', $property->toString())->where('status', 'done')->where('done_at', '>=', $from.' 00:00:00')->where('done_at', '<', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00')
            ->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function addEscalation(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('maintenance_escalations')->insert([...$row, 'property_id' => $property->toString(), 'raised_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function openEscalations(PropertyId $property): array
    {
        return DB::table('maintenance_escalations as e')->join('maintenance_work_orders as w', 'w.id', '=', 'e.work_order_id')->where('e.property_id', $property->toString())->whereNull('e.acknowledged_at')->whereIn('w.status', ['open', 'assigned', 'in_progress', 'on_hold'])
            ->orderBy('e.raised_at')->orderBy('e.level')->get(['e.*', 'w.number', 'w.title', 'w.priority', 'w.room_number', 'w.area', 'w.due_at', 'w.assigned_to'])->map(static fn (object $r): array => (array) $r)->all();
    }

    public function escalation(PropertyId $property, string $id): ?array
    {
        $r = DB::table('maintenance_escalations')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function acknowledgeEscalation(PropertyId $property, string $id, string $by, ?string $note, DateTimeImmutable $at): bool
    {
        return DB::table('maintenance_escalations')->where('property_id', $property->toString())->where('id', $id)->whereNull('acknowledged_at')->update(['acknowledged_by' => $by, 'acknowledged_at' => $at, 'note' => $note]) === 1;
    }

    public function propertiesWithOpenWork(): array
    {
        return DB::table('maintenance_work_orders')->whereIn('status', ['open', 'assigned', 'in_progress', 'on_hold'])->distinct()->pluck('property_id')->map(static fn ($p): string => (string) $p)->all();
    }
}
