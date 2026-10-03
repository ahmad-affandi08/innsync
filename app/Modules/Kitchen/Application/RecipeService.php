<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Modules\FnbSales\Application\FnbTime;
use App\Modules\FnbSales\Application\MenuAvailability;
use App\Modules\InventoryPurchasing\Application\IngredientCatalog;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The recipes of the dishes (FR-KIT-003, FR-KIT-013). A recipe says what one batch of a dish takes, in the units of the inventory, with the portions the batch makes (the yield) and
 * the standard waste of each ingredient. It is never edited: a change is a new version that takes effect on a date, and every sale is booked with the version in force on its day,
 * so an old sale always points at the recipe it was cooked from. The cost of a portion is read at the moving average of the inventory.
 */
final readonly class RecipeService
{
    public const MAX_LINES = 40;

    public function __construct(
        private RecipeStore $store,
        private TicketStore $tickets,
        private KitchenAccess $access,
        private MenuAvailability $menu,
        private IngredientCatalog $ingredients,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** The quantity of one ingredient that `$portions` portions take: the recipe's quantity for the yield, plus the standard waste, rounded up to a thousandth. */
    public static function consumed(int $quantityMilli, int $wasteBp, int $yield, int $portions): int
    {
        return intdiv($quantityMilli * $portions * (10_000 + $wasteBp) + $yield * 10_000 - 1, $yield * 10_000);
    }

    /** @return array<string, mixed> the dishes with the recipe each has and what a portion costs */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->requireView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $dishes = $this->menu->items($property);
        $latest = $this->store->latest($property);
        $inForce = $this->store->inForce($property, array_column($dishes, 'id'), $today);
        $rows = [];

        foreach ($dishes as $d) {
            $now = $inForce[$d['id']] ?? null;
            $last = $latest[$d['id']] ?? null;
            $cost = $now === null ? null : $this->portionCost($property, $now);
            $rows[] = [
                'id' => $d['id'], 'code' => $d['code'], 'name' => $d['name'], 'category' => $d['category'], 'outlet' => $d['outlet'], 'price_minor' => (int) $d['price_minor'],
                'version' => $now === null ? null : (int) $now['version'], 'effective_from' => $now['effective_from'] ?? null, 'ingredients' => $now === null ? 0 : count($now['lines']),
                'cost_minor' => $cost['cost_minor'] ?? null, 'cost_complete' => $cost['complete'] ?? true,
                'food_cost_bp' => $cost !== null && $cost['complete'] && (int) $d['price_minor'] > 0 ? intdiv($cost['cost_minor'] * 10_000, (int) $d['price_minor']) : null,
                'scheduled' => $last !== null && $now !== null && $last['id'] !== $now['id'] ? ['version' => (int) $last['version'], 'effective_from' => $last['effective_from']] : ($last !== null && $now === null ? ['version' => (int) $last['version'], 'effective_from' => $last['effective_from']] : null),
            ];
        }

        $settings = $this->tickets->settings($property);

        return [
            'currency' => $this->currencies->currencyOf($property), 'dishes' => $rows, 'business_date' => $today, 'stock_location_id' => $settings['stock_location_id'] ?? null,
            'may' => ['manage' => $this->access->may($property, $actorId, KitchenAccess::RECIPE_MANAGE)],
        ];
    }

    /** @return array<string, mixed> one dish with every version of its recipe and what the editor needs */
    public function show(PropertyId $property, string $actorId, string $menuItemId): array
    {
        $this->requireView($property, $actorId);
        $dish = $this->dish($property, strtolower($menuItemId));
        $recipe = $this->store->recipeOf($property, $dish['id']);
        $versions = $recipe === null ? [] : $this->store->versions($property, $recipe['id']);
        $today = $this->businessDate->current($property)->toString();
        $manage = $this->access->may($property, $actorId, KitchenAccess::RECIPE_MANAGE);

        return [
            'currency' => $this->currencies->currencyOf($property), 'business_date' => $today,
            'dish' => ['id' => $dish['id'], 'code' => $dish['code'], 'name' => $dish['name'], 'category' => $dish['category'], 'outlet' => $dish['outlet'], 'price_minor' => (int) $dish['price_minor']],
            'versions' => array_reverse(array_map(fn (array $v): array => $this->version($property, $v, $today), $versions)),
            'ingredients' => $manage ? $this->ingredients->items($property) : [],
            'may' => ['manage' => $manage],
        ];
    }

    /**
     * @param  list<array{item_id: string, unit: string, quantity_milli: int, waste_bp: int}>  $lines
     * @return array<string, mixed>
     */
    public function save(PropertyId $property, string $actorId, string $menuItemId, string $effectiveFrom, int $yield, array $lines, string $reason): array
    {
        $this->access->require($property, $actorId, KitchenAccess::RECIPE_MANAGE, 'This person may not write recipes.');
        $dish = $this->dish($property, strtolower($menuItemId));
        $reason = trim($reason);
        $today = $this->businessDate->current($property)->toString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $effectiveFrom) !== 1 || $effectiveFrom < $today) {
            throw Refusal::invalid('A recipe takes effect today or later. A day that has passed has its sales booked already.', ['effective_from']);
        }

        if ($yield < 1 || $yield > 1000) {
            throw Refusal::invalid('A batch makes between 1 and 1000 portions.', ['yield_portions']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason, in at most 200 characters.', ['reason']);
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('A recipe has between 1 and '.self::MAX_LINES.' ingredients.', ['lines']);
        }

        $rows = [];
        $seen = [];

        foreach ($lines as $l) {
            $itemId = strtolower((string) $l['item_id']);
            $item = $this->ingredients->item($property, $itemId) ?? throw Refusal::invalid('Choose an ingredient that is in use in the inventory.', ['lines']);

            if (isset($seen[$itemId])) {
                throw Refusal::invalid('An ingredient is listed once in a recipe: '.$item['name'].'.', ['lines']);
            }

            $seen[$itemId] = true;
            $unit = strtoupper(trim((string) $l['unit']));

            if (! in_array($unit, $item['units'], true)) {
                throw Refusal::invalid($item['name'].' is not counted in '.$unit.'.', ['lines']);
            }

            if ($l['quantity_milli'] < 1 || $l['quantity_milli'] > 100_000_000) {
                throw Refusal::invalid('Give each quantity above zero, with at most three decimals.', ['lines']);
            }

            if ($l['waste_bp'] < 0 || $l['waste_bp'] > 5000) {
                throw Refusal::invalid('The standard waste is between 0 and 50 percent.', ['lines']);
            }

            $rows[] = ['id' => $this->ids->next(), 'ingredient_item_id' => $item['id'], 'ingredient_code' => $item['code'], 'ingredient_name' => $item['name'], 'unit' => $unit, 'quantity_milli' => (int) $l['quantity_milli'], 'waste_bp' => (int) $l['waste_bp']];
        }

        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $dish, $effectiveFrom, $yield, $rows, $reason, $now): void {
            $recipe = $this->store->recipeOf($property, $dish['id']);

            if ($recipe === null) {
                $this->store->addRecipe($property, ['id' => $this->ids->next(), 'menu_item_id' => $dish['id'], 'item_code' => $dish['code'], 'item_name' => $dish['name']], $now);
                $recipe = $this->store->recipeOf($property, $dish['id']) ?? throw Refusal::notFound('Recipe not found.');
            }

            $this->store->lockRecipe($property, $recipe['id']);
            $versions = $this->store->versions($property, $recipe['id']);
            $last = $versions === [] ? null : end($versions);

            if ($last !== null && $effectiveFrom <= $last['effective_from']) {
                throw Refusal::stateConflict('The latest version takes effect on '.$last['effective_from'].'. A new version takes effect after it.');
            }

            $number = $last === null ? 1 : (int) $last['version'] + 1;
            $id = $this->ids->next();

            if (! $this->store->addVersion($property, ['id' => $id, 'recipe_id' => $recipe['id'], 'version' => $number, 'effective_from' => $effectiveFrom, 'yield_portions' => $yield, 'reason' => $reason, 'created_by' => $actor], $rows, $now)) {
                throw Refusal::stateConflict('This recipe changed meanwhile. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'kitchen_recipe.versioned', 'kitchen_recipe', $recipe['id'], $last === null ? null : ['version' => (int) $last['version'], 'effective_from' => $last['effective_from'], 'yield' => (int) $last['yield_portions'], 'ingredients' => count($last['lines'])],
                ['item' => $dish['code'], 'version' => $number, 'effective_from' => $effectiveFrom, 'yield' => $yield, 'ingredients' => count($rows)], $reason));
        });

        return $this->show($property, $actorId, $dish['id']);
    }

    /** @return array<string, mixed> */
    public function consumptions(PropertyId $property, string $actorId): array
    {
        $this->requireView($property, $actorId);
        $rows = $this->store->consumptions($property, 100);

        return ['consumptions' => array_map(static fn (array $c): array => [
            'id' => $c['id'], 'bill_number' => $c['bill_number'], 'item_name' => $c['item_name'], 'portions' => (int) $c['portions'], 'version' => (int) $c['version'], 'business_date' => $c['business_date'],
            'ingredient' => $c['ingredient_name'], 'unit' => $c['unit'], 'quantity_milli' => (int) $c['quantity_milli'], 'at' => FnbTime::utc($c['created_at']),
        ], $rows)];
    }

    private function requireView(PropertyId $property, string $actorId): void
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, KitchenAccess::RECIPE_MANAGE) && ! $this->access->may($property, $actorId, KitchenAccess::BOARD_OPERATE)) {
            throw Refusal::forbidden('This person may not see the recipes.');
        }
    }

    /** @return array<string, mixed> */
    private function dish(PropertyId $property, string $id): array
    {
        foreach ($this->menu->items($property) as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }

        throw Refusal::notFound('Dish not found.');
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function version(PropertyId $property, array $v, string $today): array
    {
        $cost = $this->portionCost($property, $v);

        return [
            'id' => $v['id'], 'version' => (int) $v['version'], 'effective_from' => $v['effective_from'], 'yield_portions' => (int) $v['yield_portions'], 'reason' => $v['reason'], 'created_at' => FnbTime::utc($v['created_at']),
            'cost_minor' => $cost['cost_minor'], 'cost_complete' => $cost['complete'], 'scheduled' => $v['effective_from'] > $today,
            'lines' => array_map(static fn (array $l): array => ['item_id' => $l['ingredient_item_id'], 'code' => $l['ingredient_code'], 'name' => $l['ingredient_name'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli'], 'waste_bp' => (int) $l['waste_bp']], $v['lines']),
        ];
    }

    /**
     * What one portion costs at the moving average of the inventory.
     *
     * @param  array<string, mixed>  $v
     * @return array{cost_minor: int, complete: bool}
     */
    private function portionCost(PropertyId $property, array $v): array
    {
        $total = 0;
        $complete = true;

        foreach ($v['lines'] as $l) {
            $value = $this->ingredients->valueOf($property, $l['ingredient_item_id'], $l['unit'], self::consumed((int) $l['quantity_milli'], (int) $l['waste_bp'], 1, 1));

            if ($value === null) {
                $complete = false;

                continue;
            }

            $total += $value;
        }

        return ['cost_minor' => intdiv($total + intdiv((int) $v['yield_portions'], 2), (int) $v['yield_portions']), 'complete' => $complete];
    }
}
