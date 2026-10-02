<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\OutletRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseOutletRepository implements OutletRepository
{
    public function add(PropertyId $property, string $id, string $code, string $name, DateTimeImmutable $at): bool
    {
        try {
            DB::table('revenue_outlets')->insert(['id' => $id, 'property_id' => $property->toString(), 'code' => $code, 'name' => $name, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function rename(PropertyId $property, string $id, string $name, int $expectedLockVersion, DateTimeImmutable $at): bool
    {
        return DB::table('revenue_outlets')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)
            ->update(['name' => $name, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('revenue_outlets')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'lock_version' => (int) $row->lock_version];
    }

    public function all(PropertyId $property): array
    {
        $sources = DB::table('revenue_outlet_sources')->where('property_id', $property->toString())->orderBy('source')->get(['outlet_id', 'source'])->groupBy('outlet_id');

        return DB::table('revenue_outlets')->where('property_id', $property->toString())->orderBy('name')->orderBy('code')->get()
            ->map(static fn ($r): array => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'lock_version' => (int) $r->lock_version, 'sources' => ($sources[$r->id] ?? collect())->pluck('source')->values()->all()])->all();
    }

    public function addSource(PropertyId $property, string $outletId, string $source, DateTimeImmutable $at): bool
    {
        try {
            DB::table('revenue_outlet_sources')->insert(['property_id' => $property->toString(), 'source' => $source, 'outlet_id' => $outletId, 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function removeSource(PropertyId $property, string $outletId, string $source): bool
    {
        return DB::table('revenue_outlet_sources')->where('property_id', $property->toString())->where('outlet_id', $outletId)->where('source', $source)->delete() === 1;
    }

    public function unmappedSources(PropertyId $property, array $builtIn): array
    {
        return DB::table('folio_postings as p')->where('p.property_id', $property->toString())->where('p.entry_type', 'charge')->whereNotIn('p.source', $builtIn)
            ->whereNotIn('p.source', DB::table('revenue_outlet_sources')->where('property_id', $property->toString())->select('source'))
            ->distinct()->orderBy('p.source')->pluck('p.source')->all();
    }
}
