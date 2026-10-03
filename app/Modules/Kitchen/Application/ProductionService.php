<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Modules\FnbSales\Application\FnbTime;
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

/**
 * Preparation batches of semi-finished goods (FR-KIT-014): a sauce, a dough or a stock made from raw ingredients and kept as an item of the inventory.
 *
 * A formula says what one batch takes and what it should make; it is never edited, only retired and replaced. A batch is made from a formula, in whole batches, and the person says
 * what it really made (the actual yield). The ingredients are taken out of the pantry in the quantities of the formula; what comes in is the actual yield, valued at what the
 * ingredients cost, so a poor yield makes each portion of the sauce dearer. The kitchen publishes the batch and the inventory books it once (a movement out for each ingredient, one in
 * for the product). Batches are never edited; a mistake is answered with a stock adjustment.
 */
final readonly class ProductionService
{
    public const EVENT = 'kitchen.production.recorded';

    public const MAX_LINES = 30;

    public const MAX_BATCHES = 100;

    /** A batch cannot make more than this many times the standard: more is a slip of a decimal, not a recipe. */
    public const MAX_YIELD_BP = 60_000;

    public function __construct(
        private ProductionStore $store,
        private TicketStore $tickets,
        private KitchenAccess $access,
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
        $record = $this->access->may($property, $actorId, KitchenAccess::PRODUCTION_RECORD);
        $manage = $this->access->may($property, $actorId, KitchenAccess::RECIPE_MANAGE);

        if (! $record && ! $manage && ! $this->access->may($property, $actorId, KitchenAccess::BOARD_OPERATE)) {
            throw Refusal::forbidden('This person may not see the production.');
        }

        $batches = $this->store->latest($property, 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($batches, 'recorded_by'))));

        return [
            'currency' => $this->currencies->currencyOf($property), 'business_date' => $this->businessDate->current($property)->toString(),
            'formulas' => array_map(static fn (array $f): array => [
                'id' => $f['id'], 'code' => $f['code'], 'name' => $f['name'], 'output_item_id' => $f['output_item_id'], 'output_name' => $f['output_name'], 'output_unit' => $f['output_unit'], 'standard_output_milli' => (int) $f['standard_output_milli'],
                'is_active' => (bool) $f['is_active'], 'retire_reason' => $f['retire_reason'],
                'lines' => array_map(static fn (array $l): array => ['item_id' => $l['ingredient_item_id'], 'code' => $l['ingredient_code'], 'name' => $l['ingredient_name'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli']], $f['lines']),
            ], $this->store->formulas($property)),
            'batches' => array_map(static fn (array $b): array => [
                'id' => $b['id'], 'number' => $b['number'], 'formula' => $b['formula_name'], 'formula_code' => $b['formula_code'], 'batches' => (int) $b['batches'], 'output_name' => $b['output_name'], 'output_unit' => $b['output_unit'],
                'standard_output_milli' => (int) $b['standard_output_milli'], 'actual_output_milli' => (int) $b['actual_output_milli'], 'yield_bp' => (int) $b['yield_bp'], 'input_value_minor' => (int) $b['input_value_minor'], 'input_value_complete' => (bool) $b['input_value_complete'],
                'expires_on' => $b['expires_on'], 'note' => $b['note'], 'business_date' => $b['business_date'], 'by' => $names[$b['recorded_by']] ?? null, 'at' => FnbTime::utc($b['created_at']),
                'lines' => array_map(static fn (array $l): array => ['name' => $l['ingredient_name'], 'unit' => $l['unit'], 'quantity_milli' => (int) $l['quantity_milli'], 'value_minor' => $l['value_minor'] === null ? null : (int) $l['value_minor']], $b['lines']),
            ], $batches),
            'items' => $manage ? $this->ingredients->items($property) : [],
            'has_location' => ($this->tickets->settings($property)['stock_location_id'] ?? null) !== null,
            'may' => ['record' => $record, 'manage' => $manage],
        ];
    }

    /**
     * @param  list<array{item_id: string, unit: string, quantity_milli: int}>  $lines
     * @return array<string, mixed>
     */
    public function defineFormula(PropertyId $property, string $actorId, string $code, string $name, string $outputItemId, string $outputUnit, int $standardOutputMilli, array $lines): array
    {
        $this->access->require($property, $actorId, KitchenAccess::RECIPE_MANAGE, 'This person may not write formulas.');
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/D', $code) !== 1) {
            throw Refusal::invalid('The code is letters, digits, dashes and underscores, at most 20.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Name the formula, in at most 80 characters.', ['name']);
        }

        $output = $this->ingredients->item($property, strtolower($outputItemId)) ?? throw Refusal::invalid('Choose the item of the inventory the batch makes.', ['output_item_id']);
        $outputUnit = strtoupper(trim($outputUnit));

        if (! in_array($outputUnit, $output['units'], true)) {
            throw Refusal::invalid($output['name'].' is not counted in '.$outputUnit.'.', ['output_unit']);
        }

        if ($standardOutputMilli < 1 || $standardOutputMilli > 100_000_000_000) {
            throw Refusal::invalid('Give what one batch should make, above zero, with at most three decimals.', ['standard_output_milli']);
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('Give between 1 and '.self::MAX_LINES.' ingredients.', ['lines']);
        }

        $rows = [];
        $seen = [];

        foreach ($lines as $l) {
            $item = $this->ingredients->item($property, strtolower($l['item_id'])) ?? throw Refusal::invalid('Choose ingredients that are in use in the inventory.', ['lines']);
            $unit = strtoupper(trim($l['unit']));

            if (isset($seen[$item['id']]) || $item['id'] === $output['id']) {
                throw Refusal::invalid('Each ingredient is listed once, and the product is not its own ingredient.', ['lines']);
            }

            if (! in_array($unit, $item['units'], true) || $l['quantity_milli'] < 1 || $l['quantity_milli'] > 100_000_000_000) {
                throw Refusal::invalid('Give each ingredient in a unit it is counted in, with a quantity above zero.', ['lines']);
            }

            $seen[$item['id']] = true;
            $rows[] = ['id' => $this->ids->next(), 'ingredient_item_id' => $item['id'], 'ingredient_code' => $item['code'], 'ingredient_name' => $item['name'], 'unit' => $unit, 'quantity_milli' => $l['quantity_milli']];
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $row = ['id' => $id, 'code' => $code, 'name' => $name, 'output_item_id' => $output['id'], 'output_name' => $output['name'], 'output_unit' => $outputUnit, 'standard_output_milli' => $standardOutputMilli, 'is_active' => true, 'created_by' => $actor];

        $this->transactions->run(function () use ($property, $actor, $row, $rows): void {
            if (! $this->store->addFormula($property, $row, $rows, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This code is used by another formula.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'kitchen_formula.created', 'kitchen_formula', $row['id'], null, ['code' => $row['code'], 'product' => $row['output_name'], 'standard_output_milli' => $row['standard_output_milli'], 'ingredients' => count($rows)]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function retireFormula(PropertyId $property, string $actorId, string $id, string $reason): array
    {
        $this->access->require($property, $actorId, KitchenAccess::RECIPE_MANAGE, 'This person may not write formulas.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        $formula = $this->store->formula($property, strtolower($id)) ?? throw Refusal::notFound('Formula not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $formula, $reason): void {
            if (! $this->store->retireFormula($property, $formula['id'], $actor, $reason, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This formula was retired already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'kitchen_formula.retired', 'kitchen_formula', $formula['id'], ['code' => $formula['code']], ['is_active' => false], $reason));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> the overview after the batch */
    public function record(PropertyId $property, string $actorId, string $formulaId, int $batches, int $actualOutputMilli, ?string $expiresOn, ?string $note): array
    {
        $this->access->require($property, $actorId, KitchenAccess::PRODUCTION_RECORD, 'This person may not record production.');
        $formula = $this->store->formula($property, strtolower($formulaId)) ?? throw Refusal::invalid('Choose a formula.', ['formula_id']);

        if (! (bool) $formula['is_active']) {
            throw Refusal::stateConflict('This formula is retired. Choose the one that replaced it.');
        }

        if ($batches < 1 || $batches > self::MAX_BATCHES) {
            throw Refusal::invalid('Give the batches, from 1 to '.self::MAX_BATCHES.'.', ['batches']);
        }

        $standard = (int) $formula['standard_output_milli'] * $batches;

        if ($actualOutputMilli < 1 || intdiv($actualOutputMilli * 10_000, $standard) > self::MAX_YIELD_BP) {
            throw Refusal::invalid('Give what the batch really made, above zero and in the unit of the formula.', ['actual_output_milli']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('This is at most 200 characters.', ['note']);
        }

        $today = $this->businessDate->current($property)->toString();

        if ($expiresOn !== null && ($expiresOn < $today || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiresOn) !== 1)) {
            throw Refusal::invalid('Give an expiry date from today.', ['expires_on']);
        }

        $location = $this->tickets->settings($property)['stock_location_id'] ?? throw Refusal::stateConflict('The kitchen has no stock location yet. Choose it in the settings of the kitchen screen.');
        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $formula, $batches, $standard, $actualOutputMilli, $expiresOn, $note, $today, $location): void {
            $number = $this->numbers->next($property, 'KPR');
            $total = 0;
            $complete = true;
            $stored = [];
            $inputs = [];

            foreach ($formula['lines'] as $l) {
                $quantity = (int) $l['quantity_milli'] * $batches;
                $value = $this->ingredients->valueOf($property, $l['ingredient_item_id'], $l['unit'], $quantity);
                $complete = $complete && $value !== null;
                $total += $value ?? 0;
                $stored[] = ['id' => $this->ids->next(), 'ingredient_item_id' => $l['ingredient_item_id'], 'ingredient_name' => $l['ingredient_name'], 'unit' => $l['unit'], 'quantity_milli' => $quantity, 'value_minor' => $value];
                $inputs[] = ['item_id' => $l['ingredient_item_id'], 'unit' => $l['unit'], 'quantity_milli' => $quantity];
            }

            $yield = intdiv($actualOutputMilli * 10_000, $standard);
            $row = [
                'id' => $id, 'number' => $number, 'formula_id' => $formula['id'], 'formula_code' => $formula['code'], 'formula_name' => $formula['name'], 'batches' => $batches, 'output_item_id' => $formula['output_item_id'], 'output_name' => $formula['output_name'],
                'output_unit' => $formula['output_unit'], 'standard_output_milli' => $standard, 'actual_output_milli' => $actualOutputMilli, 'yield_bp' => $yield, 'input_value_minor' => $total, 'input_value_complete' => $complete,
                'expires_on' => $expiresOn, 'lot_number' => $expiresOn === null ? null : $number, 'note' => $note, 'business_date' => $today, 'recorded_by' => $actor,
            ];
            $this->store->addProduction($property, $row, $stored, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'kitchen_production.recorded', 'kitchen_production', $id, null, [
                'number' => $number, 'formula' => $formula['code'], 'batches' => $batches, 'standard_output_milli' => $standard, 'actual_output_milli' => $actualOutputMilli, 'yield_bp' => $yield, 'input_value_minor' => $total,
            ], $note));
            $this->outbox->publish(new OutboxEvent($property, self::EVENT, $id, 1, [
                'production_id' => $id, 'number' => $number, 'location_id' => $location, 'actor_id' => $actor, 'inputs' => $inputs,
                'output' => ['item_id' => $formula['output_item_id'], 'unit' => $formula['output_unit'], 'quantity_milli' => $actualOutputMilli, 'expires_on' => $expiresOn, 'lot_number' => $row['lot_number']],
            ]));
        });

        return $this->overview($property, $actorId);
    }
}
