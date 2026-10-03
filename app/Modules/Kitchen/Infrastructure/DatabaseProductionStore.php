<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Infrastructure;

use App\Modules\Kitchen\Application\ProductionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseProductionStore implements ProductionStore
{
    public function formulas(PropertyId $property): array
    {
        $rows = DB::table('kitchen_prep_formulas')->where('property_id', $property->toString())->orderByDesc('is_active')->orderBy('code')->get()->map(static fn (object $r): array => (array) $r)->all();

        return $this->withLines($rows);
    }

    public function formula(PropertyId $property, string $id): ?array
    {
        $row = DB::table('kitchen_prep_formulas')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : $this->withLines([(array) $row])[0];
    }

    public function addFormula(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        try {
            DB::table('kitchen_prep_formulas')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        foreach ($lines as $line) {
            DB::table('kitchen_prep_formula_lines')->insert([...$line, 'formula_id' => $row['id']]);
        }

        return true;
    }

    public function retireFormula(PropertyId $property, string $id, string $by, string $reason, DateTimeImmutable $at): bool
    {
        return DB::table('kitchen_prep_formulas')->where('property_id', $property->toString())->where('id', $id)->where('is_active', true)
            ->update(['is_active' => false, 'retired_by' => $by, 'retired_at' => $at, 'retire_reason' => $reason, 'updated_at' => $at]) === 1;
    }

    public function addProduction(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): void
    {
        DB::table('kitchen_productions')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

        foreach ($lines as $line) {
            DB::table('kitchen_production_lines')->insert([...$line, 'production_id' => $row['id']]);
        }
    }

    public function latest(PropertyId $property, int $limit): array
    {
        $rows = DB::table('kitchen_productions')->where('property_id', $property->toString())->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
        $lines = DB::table('kitchen_production_lines')->whereIn('production_id', array_column($rows, 'id'))->orderBy('ingredient_name')->get()->groupBy('production_id');

        foreach ($rows as &$r) {
            $r['lines'] = array_values(array_map(static fn (object $l): array => (array) $l, $lines->get($r['id'])?->all() ?? []));
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withLines(array $rows): array
    {
        $lines = DB::table('kitchen_prep_formula_lines')->whereIn('formula_id', array_column($rows, 'id'))->orderBy('ingredient_name')->get()->groupBy('formula_id');

        foreach ($rows as &$r) {
            $r['lines'] = array_values(array_map(static fn (object $l): array => (array) $l, $lines->get($r['id'])?->all() ?? []));
        }

        return $rows;
    }
}
