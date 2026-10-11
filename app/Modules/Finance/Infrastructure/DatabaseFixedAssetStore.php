<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\FixedAssetStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseFixedAssetStore implements FixedAssetStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('fin_fixed_assets')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'active', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('fin_fixed_assets')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function all(PropertyId $property): array
    {
        $sums = DB::table('fin_depreciation_entries')->where('property_id', $property->toString())->groupBy('asset_id')->selectRaw('asset_id, SUM(amount_minor) as accumulated, MAX(period) as last_period, COUNT(*) as entries')->get()->keyBy('asset_id');

        return DB::table('fin_fixed_assets')->where('property_id', $property->toString())->orderBy('code')->get()->map(static function (object $r) use ($sums): array {
            $s = $sums->get($r->id);

            return [...(array) $r, 'accumulated_minor' => $s === null ? 0 : (int) $s->accumulated, 'entries' => $s === null ? 0 : (int) $s->entries, 'last_period' => $s === null ? null : (string) $s->last_period];
        })->all();
    }

    public function addEntries(PropertyId $property, string $assetId, array $entries, string $by, DateTimeImmutable $at): void
    {
        foreach ($entries as $e) {
            DB::table('fin_depreciation_entries')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => $property->toString(), 'asset_id' => $assetId, 'period' => $e['period'], 'amount_minor' => $e['amount_minor'], 'accumulated_after_minor' => $e['accumulated_after_minor'], 'created_by' => $by, 'created_at' => $at]);
        }
    }

    public function dispose(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fin_fixed_assets')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->where('status', 'active')->update([...$fields, 'status' => 'disposed', 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function accumulated(PropertyId $property, string $assetId): int
    {
        return (int) DB::table('fin_depreciation_entries')->where('property_id', $property->toString())->where('asset_id', $assetId)->sum('amount_minor');
    }
}
