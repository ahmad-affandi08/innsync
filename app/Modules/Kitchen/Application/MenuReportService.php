<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Modules\FnbSales\Application\MenuSales;
use App\Modules\InventoryPurchasing\Application\IngredientCatalog;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * The menu report of the kitchen (FR-KIT-012): what each dish sold, what its ingredients cost and how the menu does by popularity and margin.
 *
 * Sales are the settled bills of the period, net of discounts, service charge and tax. The cost of a dish is what the sales of the period took out of the pantry by the recipe
 * in force each day, valued at the moving average of the inventory now; a dish whose ingredients are not all costed yet shows its cost as partial and a dish with no recipe shows
 * none, and neither enters the cost ratio. The menu engineering classes follow the usual rule: a dish is popular when its share of the portions is at least 70% of an equal share,
 * and has a high margin when its contribution per portion reaches the average of the dishes costed, weighted by portions. Star (both), plowhorse (popular, low margin),
 * puzzle (high margin, less popular) and dog (neither); a dish needs a complete cost and a sale to be classed, and at least two such dishes are needed to class any.
 */
final readonly class MenuReportService
{
    public const MAX_DAYS = 366;

    public const POPULARITY_BP = 7000;

    public function __construct(private MenuSales $sales, private RecipeStore $recipes, private IngredientCatalog $ingredients, private KitchenAccess $access, private BusinessDateProvider $businessDate, private PropertyCurrencyReader $currencies) {}

    /** @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $outletId): array
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, KitchenAccess::REPORT_VIEW) && ! $this->access->may($property, $actorId, KitchenAccess::RECIPE_MANAGE)) {
            throw Refusal::forbidden('This person may not see the menu report.');
        }

        $today = $this->businessDate->current($property)->toString();
        $to ??= $today;
        $from ??= (new DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to);

        if ($a === false || $b === false || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to) {
            throw Refusal::invalid('Give the dates as year-month-day.', ['from']);
        }

        if ($a > $b || $a->diff($b)->days >= self::MAX_DAYS) {
            throw Refusal::invalid('The report covers at most '.self::MAX_DAYS.' days, and ends after it starts.', ['from']);
        }

        $sold = $this->sales->soldBetween($property, $from, $to);
        $outlets = [];

        foreach ($sold as $s) {
            $outlets[$s['outlet_id']] = ['id' => $s['outlet_id'], 'name' => $s['outlet']];
        }

        if ($outletId !== null) {
            $sold = array_values(array_filter($sold, static fn (array $s): bool => $s['outlet_id'] === strtolower($outletId)));
        }

        $consumed = [];

        foreach ($this->recipes->consumedBetween($property, $from, $to) as $c) {
            $consumed[$c['menu_item_id']][] = $c;
        }

        $latest = $this->recipes->latest($property);
        $rows = [];

        foreach ($sold as $s) {
            $cost = null;
            $state = 'none';

            if (isset($consumed[$s['item_id']])) {
                $cost = 0;
                $state = 'complete';

                foreach ($consumed[$s['item_id']] as $c) {
                    $value = $this->ingredients->valueOf($property, $c['ingredient_item_id'], $c['unit'], $c['quantity_milli']);

                    if ($value === null) {
                        $state = 'partial';

                        continue;
                    }

                    $cost += $value;
                }
            }

            $net = $s['net_minor'];
            $rows[] = [
                'item_id' => $s['item_id'], 'code' => $s['code'], 'name' => $s['name'], 'category' => $s['category'], 'outlet_id' => $s['outlet_id'], 'outlet' => $s['outlet'], 'portions' => $s['portions'], 'net_minor' => $net, 'discount_minor' => $s['discount_minor'],
                'avg_price_minor' => intdiv($net + intdiv($s['portions'], 2), max(1, $s['portions'])), 'has_recipe' => isset($latest[$s['item_id']]), 'cost_state' => $state, 'cost_minor' => $cost,
                'cost_bp' => $state === 'complete' && $net > 0 ? intdiv((int) $cost * 10_000, $net) : null,
                'margin_minor' => $state === 'complete' ? $net - (int) $cost : null,
                'margin_per_portion_minor' => $state === 'complete' ? intdiv($net - (int) $cost, max(1, $s['portions'])) : null,
                'mix_bp' => null, 'class' => null,
            ];
        }

        $rows = $this->classify($rows);
        usort($rows, static fn (array $x, array $y): int => [$y['net_minor'], $x['name']] <=> [$x['net_minor'], $y['name']]);
        $net = array_sum(array_column($rows, 'net_minor'));
        $costed = array_filter($rows, static fn (array $r): bool => $r['cost_state'] === 'complete');
        $costedNet = array_sum(array_column($costed, 'net_minor'));
        $cost = array_sum(array_column($costed, 'cost_minor'));

        return [
            'currency' => $this->currencies->currencyOf($property), 'from' => $from, 'to' => $to, 'outlet_id' => $outletId === null ? null : strtolower($outletId), 'outlets' => array_values($outlets), 'rows' => $rows,
            'totals' => ['portions' => array_sum(array_column($rows, 'portions')), 'net_minor' => $net, 'discount_minor' => array_sum(array_column($rows, 'discount_minor')), 'costed_net_minor' => $costedNet, 'cost_minor' => $cost, 'cost_bp' => $costedNet > 0 ? intdiv($cost * 10_000, $costedNet) : null, 'costed_dishes' => count($costed), 'dishes' => count($rows)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function classify(array $rows): array
    {
        $eligible = array_keys(array_filter($rows, static fn (array $r): bool => $r['cost_state'] === 'complete' && $r['portions'] > 0));

        if (count($eligible) < 2) {
            return $rows;
        }

        $portions = 0;
        $margin = 0;

        foreach ($eligible as $i) {
            $portions += $rows[$i]['portions'];
            $margin += (int) $rows[$i]['margin_minor'];
        }

        $averageMargin = intdiv($margin, $portions);
        $threshold = intdiv(self::POPULARITY_BP * intdiv(10_000, count($eligible)), 10_000);

        foreach ($eligible as $i) {
            $mix = intdiv($rows[$i]['portions'] * 10_000, $portions);
            $popular = $mix >= $threshold;
            $high = (int) $rows[$i]['margin_per_portion_minor'] >= $averageMargin;
            $rows[$i]['mix_bp'] = $mix;
            $rows[$i]['class'] = $popular ? ($high ? 'star' : 'plowhorse') : ($high ? 'puzzle' : 'dog');
        }

        return $rows;
    }
}
