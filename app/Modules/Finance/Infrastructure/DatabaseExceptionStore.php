<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\ExceptionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseExceptionStore implements ExceptionStore
{
    public function exceptions(PropertyId $property, ?string $status): array
    {
        return $this->query($property)->when($status !== null, static fn ($q) => $q->where('e.status', $status))->orderByRaw("e.status = 'open' DESC")->orderByDesc('e.business_date')->orderByDesc('e.number')->limit(500)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function exception(PropertyId $property, string $id): ?array
    {
        $row = $this->query($property)->where('e.id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('fin_exceptions')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fin_exceptions')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'open', 'lock_version' => 0, 'created_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function resolve(PropertyId $property, string $id, int $lock, string $status, string $resolution, ?string $correctionId, string $by, DateTimeImmutable $at): bool
    {
        return DB::table('fin_exceptions')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->where('lock_version', $lock)
            ->update(['status' => $status, 'resolution' => $resolution, 'correction_id' => $correctionId, 'resolved_by' => $by, 'resolved_at' => $at, 'lock_version' => $lock + 1]) === 1;
    }

    public function openCount(PropertyId $property): int
    {
        return DB::table('fin_exceptions')->where('property_id', $property->toString())->where('status', 'open')->count();
    }

    private function query(PropertyId $property): Builder
    {
        return DB::table('fin_exceptions as e')->leftJoin('fin_corrections as c', 'c.id', '=', 'e.correction_id')->where('e.property_id', $property->toString())->select(['e.*', 'c.number as correction_number']);
    }
}
