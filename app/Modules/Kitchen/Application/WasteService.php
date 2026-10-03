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
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * The waste log of the kitchen (FR-KIT-006). Whoever may record waste says what was thrown away and why: an ingredient, by its quantity, or a dish, by its portions, which the
 * recipe in force turns into the ingredients it took. Each entry is kept as it was said, with who and when and what it cost at the moving average of the stock (marked partial when an
 * ingredient has no cost yet), and is told to the inventory, which writes the stock off from the location the kitchen takes its ingredients from. The log is never edited: a wrong
 * entry is not undone, it is answered with a stock adjustment. Stock written off is cost in the food cost report of finance.
 */
final readonly class WasteService
{
    public const REASONS = ['spoiled', 'expired', 'damaged', 'overcooked', 'returned', 'wrong_order', 'other'];

    public const EVENT = 'kitchen.waste.recorded';

    /** How each reason is written on the stock movement, in the reasons the inventory knows. */
    private const STOCK_REASON = ['spoiled' => 'spoiled', 'expired' => 'expired', 'damaged' => 'damaged'];

    public function __construct(
        private WasteStore $store,
        private RecipeStore $recipes,
        private TicketStore $tickets,
        private KitchenAccess $access,
        private MenuAvailability $menu,
        private IngredientCatalog $ingredients,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
        private StaffDirectory $staff,
        private DocumentNumbers $numbers,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $record = $this->access->may($property, $actorId, KitchenAccess::WASTE_RECORD);

        if (! $record && ! $this->access->may($property, $actorId, KitchenAccess::BOARD_OPERATE)) {
            throw Refusal::forbidden('This person may not see the waste log.');
        }

        $today = $this->businessDate->current($property)->toString();
        $rows = $this->store->latest($property, 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'recorded_by'))));
        $month = (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
        $inForce = $record ? $this->recipes->inForce($property, array_column($this->menu->items($property), 'id'), $today) : [];

        return [
            'currency' => $this->currencies->currencyOf($property), 'business_date' => $today, 'reasons' => self::REASONS,
            'entries' => array_map(static fn (array $w): array => [
                'id' => $w['id'], 'number' => $w['number'], 'kind' => $w['kind'], 'dish' => $w['menu_item_name'], 'portions' => $w['portions'] === null ? null : (int) $w['portions'], 'reason' => $w['reason'], 'reference' => $w['reference'], 'note' => $w['note'],
                'business_date' => $w['business_date'], 'value_minor' => $w['value_minor'] === null ? null : (int) $w['value_minor'], 'value_complete' => (bool) $w['value_complete'], 'by' => $names[$w['recorded_by']] ?? null, 'at' => FnbTime::utc($w['created_at']),
                'lines' => array_map(static fn (array $l): array => ['name' => $l['ingredient_name'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli']], $w['lines']),
            ], $rows),
            'summary' => ['since' => $month, 'by_reason' => $this->store->since($property, $month), 'today_minor' => array_sum(array_map(static fn (array $w): int => $w['business_date'] === $today ? (int) $w['value_minor'] : 0, $rows))],
            'ingredients' => $record ? $this->ingredients->items($property) : [],
            'dishes' => $record ? array_values(array_map(static fn (array $d): array => ['id' => $d['id'], 'name' => $d['name'], 'outlet' => $d['outlet']], array_filter($this->menu->items($property), static fn (array $d): bool => isset($inForce[$d['id']])))) : [],
            'has_location' => ($this->tickets->settings($property)['stock_location_id'] ?? null) !== null,
            'may' => ['record' => $record],
        ];
    }

    /** @return array<string, mixed> the overview after the entry */
    public function record(PropertyId $property, string $actorId, string $kind, string $itemId, ?int $quantityMilli, ?string $unit, ?int $portions, string $reason, ?string $reference, ?string $note): array
    {
        $this->access->require($property, $actorId, KitchenAccess::WASTE_RECORD, 'This person may not record waste.');
        $reference = $this->text($reference, 40, 'reference');
        $note = $this->text($note, 200, 'note');

        if (! in_array($reason, self::REASONS, true)) {
            throw Refusal::invalid('Choose why it was thrown away.', ['reason']);
        }

        if ($reason === 'other' && $note === null) {
            throw Refusal::invalid('Say what happened when the reason is other.', ['note']);
        }

        $location = $this->tickets->settings($property)['stock_location_id'] ?? throw Refusal::stateConflict('The kitchen has no stock location yet. Choose it in the settings of the kitchen screen.');
        $today = $this->businessDate->current($property)->toString();
        $itemId = strtolower($itemId);
        $row = ['id' => $this->ids->next(), 'kind' => $kind, 'menu_item_id' => null, 'menu_item_name' => null, 'portions' => null, 'recipe_version_id' => null, 'reason' => $reason, 'reference' => $reference, 'note' => $note, 'business_date' => $today, 'recorded_by' => strtolower($actorId)];
        $lines = [];

        if ($kind === 'ingredient') {
            $item = $this->ingredients->item($property, $itemId) ?? throw Refusal::invalid('Choose an ingredient that is in use in the inventory.', ['item_id']);
            $unit = strtoupper(trim((string) $unit));

            if (! in_array($unit, $item['units'], true)) {
                throw Refusal::invalid($item['name'].' is not counted in '.$unit.'.', ['unit']);
            }

            if ($quantityMilli === null || $quantityMilli < 1 || $quantityMilli > 100_000_000) {
                throw Refusal::invalid('Give the quantity above zero, with at most three decimals.', ['quantity_milli']);
            }

            $lines[] = ['item_id' => $item['id'], 'name' => $item['name'], 'unit' => $unit, 'quantity_milli' => $quantityMilli];
        } elseif ($kind === 'dish') {
            $dish = null;

            foreach ($this->menu->items($property) as $d) {
                if ($d['id'] === $itemId) {
                    $dish = $d;
                }
            }

            $dish ?? throw Refusal::invalid('Choose a dish of the menu.', ['item_id']);

            if ($portions === null || $portions < 1 || $portions > 999) {
                throw Refusal::invalid('Give the portions, between 1 and 999.', ['portions']);
            }

            $version = $this->recipes->inForce($property, [$dish['id']], $today)[$dish['id']] ?? throw Refusal::stateConflict('This dish has no recipe in force, so what it took is not known. Record the ingredients that were thrown away instead.');

            foreach ($version['lines'] as $l) {
                $lines[] = ['item_id' => $l['ingredient_item_id'], 'name' => $l['ingredient_name'], 'unit' => $l['unit'], 'quantity_milli' => RecipeService::consumed((int) $l['quantity_milli'], (int) $l['waste_bp'], (int) $version['yield_portions'], $portions)];
            }

            $row['menu_item_id'] = $dish['id'];
            $row['menu_item_name'] = $dish['name'];
            $row['portions'] = $portions;
            $row['recipe_version_id'] = $version['id'];
        } else {
            throw Refusal::invalid('Choose an ingredient or a dish.', ['kind']);
        }

        $now = $this->clock->nowUtc();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $row, $lines, $location, $actor, $now, $reason, $kind): void {
            $id = $row['id'];
            $row['number'] = $this->numbers->next($property, 'KWL');
            $total = 0;
            $complete = true;
            $stored = [];

            foreach ($lines as $l) {
                $value = $this->ingredients->valueOf($property, $l['item_id'], $l['unit'], $l['quantity_milli']);
                $complete = $complete && $value !== null;
                $total += $value ?? 0;
                $stored[] = ['id' => $this->ids->next(), 'ingredient_item_id' => $l['item_id'], 'ingredient_name' => $l['name'], 'unit' => $l['unit'], 'quantity_milli' => $l['quantity_milli'], 'value_minor' => $value];
            }

            $row['value_minor'] = $total;
            $row['value_complete'] = $complete;
            $this->store->add($property, $row, $stored, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'kitchen_waste.recorded', 'kitchen_waste', $id, null, [
                'number' => $row['number'], 'kind' => $kind, 'dish' => $row['menu_item_name'], 'portions' => $row['portions'], 'reason' => $reason, 'value_minor' => $total, 'ingredients' => count($stored),
            ], $row['note'] ?? $reason));
            $this->outbox->publish(new OutboxEvent($property, self::EVENT, $id, 1, [
                'waste_id' => $id, 'number' => $row['number'], 'location_id' => $location, 'actor_id' => $actor, 'reason' => self::STOCK_REASON[$reason] ?? 'other', 'note' => $row['note'] ?? $reason,
                'entries' => array_map(static fn (array $l): array => ['item_id' => $l['ingredient_item_id'], 'unit' => $l['unit'], 'quantity_milli' => $l['quantity_milli']], $stored),
            ]));
        });

        return $this->overview($property, $actorId);
    }

    private function text(?string $text, int $max, string $field): ?string
    {
        $text = $text === null ? null : trim($text);
        $text = $text === '' ? null : $text;

        if ($text !== null && mb_strlen($text) > $max) {
            throw Refusal::invalid("This is at most {$max} characters.", [$field]);
        }

        return $text;
    }
}
