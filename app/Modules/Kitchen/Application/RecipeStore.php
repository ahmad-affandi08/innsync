<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the recipes, their versions and what sales consumed by them. Rows are plain arrays; every query is scoped to the property. */
interface RecipeStore
{
    /** @return array<string, mixed>|null the recipe of a menu item */
    public function recipeOf(PropertyId $property, string $menuItemId): ?array;

    /** @param array<string, mixed> $row @return bool false when the item has a recipe already */
    public function addRecipe(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** Locks the recipe for the rest of the transaction. */
    public function lockRecipe(PropertyId $property, string $recipeId): void;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     * @return bool false when the recipe has a version for that date already
     */
    public function addVersion(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> every version of a recipe, oldest first, with its `lines` */
    public function versions(PropertyId $property, string $recipeId): array;

    /** @return array<string, array<string, mixed>> the version in force on a date for each menu item that has one, by menu item, with its `lines` */
    public function inForce(PropertyId $property, array $menuItemIds, string $date): array;

    /** @return array<string, array<string, mixed>> the latest version of each recipe, by menu item, with the number of its lines */
    public function latest(PropertyId $property): array;

    /**
     * What a sold line consumed. A row for a line and ingredient that exists is left alone.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> the rows that were new
     */
    public function addConsumptions(PropertyId $property, array $rows, DateTimeImmutable $at): array;

    /** @return list<array{menu_item_id: string, ingredient_item_id: string, unit: string, quantity_milli: int}> what sales took out of stock between two business dates (both included), by dish and ingredient */
    public function consumedBetween(PropertyId $property, string $from, string $to): array;

    /** @return list<array<string, mixed>> the latest consumptions, newest first */
    public function consumptions(PropertyId $property, int $limit): array;
}
