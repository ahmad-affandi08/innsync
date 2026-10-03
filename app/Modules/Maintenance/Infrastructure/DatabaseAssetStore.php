<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\AssetStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseAssetStore implements AssetStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_assets')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'active', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('maintenance_assets')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('maintenance_assets')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('maintenance_assets')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function all(PropertyId $property): array
    {
        $assets = DB::table('maintenance_assets')->where('property_id', $property->toString())->orderBy('number')->get()->map(static fn (object $r): array => (array) $r)->all();
        $reading = [];
        $due = [];

        foreach (DB::table('maintenance_meter_readings as m')->join('maintenance_assets as a', 'a.id', '=', 'm.asset_id')->where('a.property_id', $property->toString())->groupBy('m.asset_id')->get(['m.asset_id as asset', DB::raw('MAX(m.reading) as latest')]) as $r) {
            $reading[(string) $r->asset] = (int) $r->latest;
        }

        foreach (DB::table('maintenance_pm_plans')->where('property_id', $property->toString())->where('is_active', true)->whereNotNull('next_due_on')->groupBy('asset_id')->get(['asset_id as asset', DB::raw('MIN(next_due_on) as soonest')]) as $r) {
            $due[(string) $r->asset] = (string) $r->soonest;
        }

        foreach ($assets as &$a) {
            $a['reading'] = $reading[$a['id']] ?? null;
            $a['next_due_on'] = $due[$a['id']] ?? null;
        }

        return $assets;
    }

    public function addReading(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_meter_readings')->insert([...$row, 'created_at' => $at]);
    }

    public function currentReading(PropertyId $property, string $assetId): ?int
    {
        $v = DB::table('maintenance_meter_readings as m')->join('maintenance_assets as a', 'a.id', '=', 'm.asset_id')->where('a.property_id', $property->toString())->where('m.asset_id', $assetId)->max('m.reading');

        return $v === null ? null : (int) $v;
    }

    public function readings(PropertyId $property, string $assetId, int $limit): array
    {
        return DB::table('maintenance_meter_readings as m')->join('maintenance_assets as a', 'a.id', '=', 'm.asset_id')->where('a.property_id', $property->toString())->where('m.asset_id', $assetId)
            ->orderByDesc('m.created_at')->orderByDesc('m.id')->limit($limit)->get(['m.*'])->map(static fn (object $r): array => (array) $r)->all();
    }

    public function addPlan(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_pm_plans')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function plan(PropertyId $property, string $id): ?array
    {
        $r = DB::table('maintenance_pm_plans')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function plans(PropertyId $property, ?string $assetId): array
    {
        $q = DB::table('maintenance_pm_plans as p')->join('maintenance_assets as a', 'a.id', '=', 'p.asset_id')->where('p.property_id', $property->toString());

        if ($assetId !== null) {
            $q->where('p.asset_id', $assetId);
        }

        return $q->orderBy('p.title')->get(['p.*', 'a.name as asset_name', 'a.number as asset_number', 'a.status as asset_status', 'a.room_id as asset_room_id', 'a.room_number as asset_room_number', 'a.area as asset_area', 'a.meter_unit as asset_meter_unit'])->map(static fn (object $r): array => (array) $r)->all();
    }

    public function updatePlan(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('maintenance_pm_plans')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function workOrdersOf(PropertyId $property, string $assetId, int $limit): array
    {
        return DB::table('maintenance_work_orders')->where('property_id', $property->toString())->where('asset_id', $assetId)->orderByDesc('reported_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }
}
