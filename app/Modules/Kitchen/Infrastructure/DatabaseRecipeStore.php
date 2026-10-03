<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Infrastructure;

use App\Modules\Kitchen\Application\RecipeStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRecipeStore implements RecipeStore
{
    public function recipeOf(PropertyId $property, string $menuItemId): ?array
    {
        $row = DB::table('kitchen_recipes')->where('property_id', $property->toString())->where('menu_item_id', $menuItemId)->first();

        return $row === null ? null : (array) $row;
    }

    public function addRecipe(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('kitchen_recipes')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at, 'updated_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function lockRecipe(PropertyId $property, string $recipeId): void
    {
        DB::table('kitchen_recipes')->where('property_id', $property->toString())->where('id', $recipeId)->lockForUpdate()->first();
    }

    public function addVersion(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        try {
            DB::table('kitchen_recipe_versions')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }

        foreach ($lines as $line) {
            DB::table('kitchen_recipe_lines')->insert([...$line, 'version_id' => $row['id']]);
        }

        return true;
    }

    public function versions(PropertyId $property, string $recipeId): array
    {
        return $this->withLines(DB::table('kitchen_recipe_versions')->where('property_id', $property->toString())->where('recipe_id', $recipeId)->orderBy('version')->get()->map(static fn (object $r): array => (array) $r)->all());
    }

    public function inForce(PropertyId $property, array $menuItemIds, string $date): array
    {
        if ($menuItemIds === []) {
            return [];
        }

        $rows = DB::table('kitchen_recipe_versions as v')->join('kitchen_recipes as r', 'r.id', '=', 'v.recipe_id')
            ->where('v.property_id', $property->toString())->whereIn('r.menu_item_id', $menuItemIds)->where('v.effective_from', '<=', $date)
            ->orderBy('v.effective_from')->get(['v.*', 'r.menu_item_id'])->map(static fn (object $r): array => (array) $r)->all();
        $latest = [];

        foreach ($rows as $row) {
            $latest[$row['menu_item_id']] = $row;
        }

        $with = $this->withLines(array_values($latest));
        $out = [];

        foreach ($with as $v) {
            $out[$v['menu_item_id']] = $v;
        }

        return $out;
    }

    public function latest(PropertyId $property): array
    {
        $rows = DB::table('kitchen_recipe_versions as v')->join('kitchen_recipes as r', 'r.id', '=', 'v.recipe_id')->where('v.property_id', $property->toString())
            ->orderBy('v.version')->get(['v.*', 'r.menu_item_id'])->map(static fn (object $r): array => (array) $r)->all();
        $latest = [];

        foreach ($rows as $row) {
            $latest[$row['menu_item_id']] = $row;
        }

        $out = [];

        foreach ($this->withLines(array_values($latest)) as $v) {
            $out[$v['menu_item_id']] = $v;
        }

        return $out;
    }

    public function addConsumptions(PropertyId $property, array $rows, DateTimeImmutable $at): array
    {
        $added = [];

        foreach ($rows as $row) {
            try {
                DB::table('kitchen_consumptions')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
                $added[] = $row;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
            }
        }

        return $added;
    }

    public function consumptions(PropertyId $property, int $limit): array
    {
        return DB::table('kitchen_consumptions')->where('property_id', $property->toString())->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $versions
     * @return list<array<string, mixed>>
     */
    private function withLines(array $versions): array
    {
        $lines = DB::table('kitchen_recipe_lines')->whereIn('version_id', array_column($versions, 'id'))->orderBy('ingredient_name')->get()->groupBy('version_id');

        foreach ($versions as &$v) {
            $v['lines'] = array_values(array_map(static fn (object $l): array => (array) $l, $lines->get($v['id'])?->all() ?? []));
        }

        return $versions;
    }
}
