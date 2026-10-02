<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Quotation comparison (FR-PUR-005). A quotation a supplier gave (price, until when it holds, lead time, the smallest quantity it is for) is only ever
 * added. For an item, the comparison puts beside each other the price lists and the valid quotations of every active supplier, each price brought to
 * one base unit so that a carton and a bottle can be compared, with the supplier's payment terms and rating, cheapest first. It is read before an order
 * is made; the order itself is made as usual and takes the price list unless Purchasing gives the quoted price.
 */
final readonly class SupplierQuoteService
{
    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
        private PurchasingAccess $access,
        private BusinessDateProvider $businessDate,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyCurrencyReader $currency,
    ) {}

    /**
     * @param  list<string>  $itemIds  the items to compare; none means the items that have a quotation
     * @return array<string, mixed>
     */
    public function overview(PropertyId $property, string $actorId, array $itemIds): array
    {
        $this->access->requireView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $items = array_column($this->inventory->items($property), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $quotes = $this->store->quotes($property, 200);
        $itemIds = array_values(array_unique(array_map('strtolower', array_filter($itemIds))));
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($quotes, 'created_by'))));

        if ($itemIds === []) {
            $itemIds = array_slice(array_values(array_unique(array_map(static fn (array $q): string => $q['item_id'], $this->store->validQuotes($property, $today, null)))), 0, 10);
        }

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today,
            'compare' => array_values(array_filter(array_map(fn (string $id): ?array => isset($items[$id]) ? $this->compareItem($property, $items[$id], $suppliers, $today) : null, $itemIds))),
            'quotes' => array_map(static fn (array $q): array => [
                'id' => $q['id'], 'supplier_name' => $suppliers[$q['supplier_id']]['name'] ?? '', 'item_code' => $items[$q['item_id']]['code'] ?? '', 'item_name' => $items[$q['item_id']]['name'] ?? '', 'unit' => $q['unit'], 'unit_price_minor' => (int) $q['unit_price_minor'],
                'min_qty_milli' => (int) $q['min_qty_milli'], 'quoted_on' => substr((string) $q['quoted_on'], 0, 10), 'valid_until' => substr((string) $q['valid_until'], 0, 10), 'lead_time_days' => (int) $q['lead_time_days'], 'reference' => $q['reference'], 'note' => $q['note'],
                'created_by_name' => $names[$q['created_by']] ?? null, 'valid' => substr((string) $q['valid_until'], 0, 10) >= $today && substr((string) $q['quoted_on'], 0, 10) <= $today,
            ], $quotes),
            'suppliers' => array_values(array_map(static fn (array $s): array => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name']], array_filter($suppliers, static fn (array $s): bool => (bool) $s['is_active']))),
            'items' => $this->itemChoices($property), 'selected' => $itemIds,
            'may' => ['record' => $this->access->may($property, $actorId, PurchasingAccess::QUOTE_MANAGE) || $this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function record(PropertyId $property, string $actorId, string $supplierId, string $itemId, string $unit, int $unitPriceMinor, ?string $quotedOn, string $validUntil, int $leadTimeDays, ?string $minQuantity, ?string $reference, ?string $note): array
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, PurchasingAccess::QUOTE_MANAGE) && ! $this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE)) {
            throw Refusal::forbidden('This person may not record supplier quotations.');
        }

        $supplier = $this->store->supplier($property, strtolower($supplierId)) ?? throw Refusal::invalid('Choose a supplier.', ['supplier_id']);
        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::invalid('Choose an item.', ['item_id']);
        $unit = strtoupper(trim($unit));

        if (! (bool) $supplier['is_active']) {
            throw Refusal::stateConflict('This supplier is inactive.');
        }

        if ($unit !== $item['base_unit'] && $this->inventory->currentUnit($property, $item['id'], $unit) === null) {
            throw Refusal::invalid('This item has no conversion for that unit.', ['unit']);
        }

        if ($unitPriceMinor < 0 || $unitPriceMinor > StockValue::MAX_UNIT_COST_MINOR) {
            throw Refusal::invalid('Give the price as a whole amount of at most 100,000,000.', ['unit_price_minor']);
        }

        $today = $this->businessDate->current($property)->toString();
        $quotedOn ??= $today;

        foreach (['quoted_on' => $quotedOn, 'valid_until' => $validUntil] as $field => $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw Refusal::invalid('Give the date as year-month-day.', [$field]);
            }
        }

        if ($validUntil < $quotedOn) {
            throw Refusal::invalid('A quotation cannot expire before it was given.', ['valid_until']);
        }

        if ($leadTimeDays < 0 || $leadTimeDays > 365) {
            throw Refusal::invalid('Lead time is from 0 to 365 days.', ['lead_time_days']);
        }

        $min = 0;

        if ($minQuantity !== null && trim($minQuantity) !== '') {
            $min = StockQuantity::parse($minQuantity) ?? throw Refusal::invalid('Give the smallest quantity as a number with at most three decimals.', ['min_quantity']);
        }

        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($reference !== null && mb_strlen($reference) > 40) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The reference is at most 40 characters and the note at most 200.', ['reference']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $supplier, $item, $unit, $unitPriceMinor, $quotedOn, $validUntil, $leadTimeDays, $min, $reference, $note): void {
            $this->store->addQuote($property, ['id' => $id, 'supplier_id' => $supplier['id'], 'item_id' => $item['id'], 'unit' => $unit, 'unit_price_minor' => $unitPriceMinor, 'min_qty_milli' => $min, 'quoted_on' => $quotedOn, 'valid_until' => $validUntil,
                'lead_time_days' => $leadTimeDays, 'reference' => $reference, 'note' => $note, 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_quote.recorded', 'supplier', $supplier['id'], null, ['item' => $item['code'], 'unit' => $unit, 'unit_price_minor' => $unitPriceMinor, 'valid_until' => $validUntil], $note));
        });

        return $this->overview($property, $actorId, [$item['id']]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $suppliers
     * @return array<string, mixed>
     */
    private function compareItem(PropertyId $property, array $item, array $suppliers, string $today): array
    {
        $factors = [$item['base_unit'] => 1000];

        foreach ($this->inventory->unitVersions($property) as $u) {
            if ($u['item_id'] === $item['id']) {
                $factors[$u['unit']] = (int) $u['factor_milli'];
            }
        }

        $options = [];
        $seen = [];

        foreach ($this->store->startedPrices($property, $today) as $p) {
            $key = $p['supplier_id'].'|'.$p['item_id'].'|'.$p['unit'];

            if ($p['item_id'] !== $item['id'] || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $options[] = $this->option($suppliers[$p['supplier_id']] ?? null, $p['unit'], (int) $p['unit_price_minor'], 'price_list', null, null, 0, $factors);
        }

        $seenQuote = [];

        foreach ($this->store->validQuotes($property, $today, $item['id']) as $q) {
            $key = $q['supplier_id'].'|'.$q['unit'];

            if (isset($seenQuote[$key])) {
                continue;
            }

            $seenQuote[$key] = true;
            $options[] = $this->option($suppliers[$q['supplier_id']] ?? null, $q['unit'], (int) $q['unit_price_minor'], 'quote', substr((string) $q['valid_until'], 0, 10), (int) $q['lead_time_days'], (int) $q['min_qty_milli'], $factors);
        }

        $options = array_values(array_filter($options, static fn (array $o): bool => $o['supplier'] !== null && $o['base_price_minor'] !== null));
        usort($options, static fn (array $a, array $b): int => [$a['base_price_minor'], $a['supplier']['name']] <=> [$b['base_price_minor'], $b['supplier']['name']]);
        $best = $options[0]['base_price_minor'] ?? null;

        return [
            'item' => ['id' => $item['id'], 'code' => $item['code'], 'name' => $item['name'], 'base_unit' => $item['base_unit']],
            'options' => array_map(static fn (array $o): array => [...$o, 'cheapest' => $best !== null && $o['base_price_minor'] === $best, 'over_cheapest_bp' => $best === null || $best === 0 ? 0 : intdiv(($o['base_price_minor'] - $best) * 10000, $best)], $options),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $supplier
     * @param  array<string, int>  $factors
     * @return array<string, mixed>
     */
    private function option(?array $supplier, string $unit, int $price, string $source, ?string $validUntil, ?int $lead, int $minQty, array $factors): array
    {
        $factor = $factors[$unit] ?? null;

        return [
            'supplier' => $supplier === null || ! (bool) $supplier['is_active'] ? null : ['id' => $supplier['id'], 'code' => $supplier['code'], 'name' => $supplier['name'], 'payment_terms_days' => (int) $supplier['payment_terms_days'], 'rating_avg' => isset($supplier['rating_avg']) && $supplier['rating_avg'] !== null ? round((float) $supplier['rating_avg'], 1) : null],
            'unit' => $unit, 'unit_price_minor' => $price, 'source' => $source, 'valid_until' => $validUntil, 'lead_time_days' => $lead, 'min_qty_milli' => $minQty,
            'base_price_minor' => $factor === null || $factor < 1 ? null : StockValue::mulDiv($price, 1000, $factor),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function itemChoices(PropertyId $property): array
    {
        $units = [];

        foreach ($this->inventory->unitVersions($property) as $u) {
            $units[$u['item_id']][$u['unit']] = $u['unit'];
        }

        return array_values(array_map(static fn (array $i): array => ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit'], 'units' => [$i['base_unit'], ...array_values($units[$i['id']] ?? [])]], array_filter($this->inventory->items($property), static fn (array $i): bool => (bool) $i['is_active'])));
    }
}
