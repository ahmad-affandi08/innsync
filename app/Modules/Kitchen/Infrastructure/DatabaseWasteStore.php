<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Infrastructure;

use App\Modules\Kitchen\Application\WasteStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseWasteStore implements WasteStore
{
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): void
    {
        DB::table('kitchen_waste')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

        foreach ($lines as $line) {
            DB::table('kitchen_waste_lines')->insert([...$line, 'waste_id' => $row['id']]);
        }
    }

    public function latest(PropertyId $property, int $limit): array
    {
        $rows = DB::table('kitchen_waste')->where('property_id', $property->toString())->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
        $lines = DB::table('kitchen_waste_lines')->whereIn('waste_id', array_column($rows, 'id'))->orderBy('ingredient_name')->get()->groupBy('waste_id');

        foreach ($rows as &$r) {
            $r['lines'] = array_values(array_map(static fn (object $l): array => (array) $l, $lines->get($r['id'])?->all() ?? []));
        }

        return $rows;
    }

    public function since(PropertyId $property, string $date): array
    {
        return DB::table('kitchen_waste')->where('property_id', $property->toString())->where('business_date', '>=', $date)->groupBy('reason')->orderBy('reason')
            ->get(['reason', DB::raw('COUNT(*) as n'), DB::raw('COALESCE(SUM(value_minor), 0) as value')])
            ->map(static fn (object $r): array => ['reason' => (string) $r->reason, 'entries' => (int) $r->n, 'value_minor' => (int) $r->value])->all();
    }
}
