<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Suppliers (FR-PUR-004), kept apart from items: contact, payment terms, the tax number (NPWP), a price list per item and unit and a rating history.
 * A price is never edited: a new price is a new row with the date it applies from, and the price in force on a day is the latest one that started
 * on or before it. A rating is added and never changed. The code of a supplier never changes.
 */
final readonly class SupplierService
{
    public const MANAGE_PERMISSION = 'purchasing.supplier.manage';

    public const VIEW_PERMISSION = 'purchasing.supplier.view';

    public const RATE_PERMISSION = 'purchasing.supplier.rate';

    public const ASPECTS = ['overall', 'quality', 'delivery', 'price', 'service'];

    public const MAX_TERMS_DAYS = 180;

    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
        private PropertyCurrencyReader $currency,
    ) {}

    /** @return array{suppliers: list<array<string, mixed>>, max_terms_days: int, may: array{manage: bool, rate: bool}} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorizeView($property, $actorId);

        return [
            'suppliers' => array_map(fn (array $s): array => $this->shape($s), $this->store->suppliers($property)),
            'max_terms_days' => self::MAX_TERMS_DAYS,
            'may' => $this->mayOf($property, $actorId),
        ];
    }

    /**
     * A supplier with its price list (the price in force today and the history) and its ratings.
     *
     * @return array<string, mixed>
     */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->authorizeView($property, $actorId);
        $supplier = $this->store->supplier($property, strtolower($id)) ?? throw Refusal::notFound('Supplier not found.');
        $items = array_column($this->inventory->items($property), null, 'id');
        $today = $this->businessDate->current($property)->toString();
        $prices = $this->store->supplierPrices($property, $supplier['id']);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_merge(array_column($prices, 'created_by'), array_column($this->store->supplierRatings($property, $supplier['id'], 100), 'created_by')))));
        $inForce = [];

        foreach ($prices as $p) {
            $key = $p['item_id'].'|'.$p['unit'];

            if (! isset($inForce[$key]) && substr((string) $p['valid_from'], 0, 10) <= $today) {
                $inForce[$key] = $p['id'];
            }
        }

        $utc = static fn (mixed $v): string => (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $rated = array_filter($this->store->suppliers($property), static fn (array $s): bool => $s['id'] === $supplier['id']);

        return [
            ...$this->shape([...$supplier, ...(array_values($rated)[0] ?? [])]),
            'currency' => $this->currency->currencyOf($property), 'aspects' => self::ASPECTS, 'items' => $this->itemChoices($property), 'payable_minor' => $this->store->supplierBalance($property, $supplier['id']),
            'ledger' => array_map(static fn (array $e): array => ['id' => $e['id'], 'kind' => $e['kind'], 'amount_minor' => (int) $e['amount_minor'], 'ref_type' => $e['ref_type'], 'ref_id' => $e['ref_id'], 'ref_number' => $e['ref_number'], 'business_date' => substr((string) $e['business_date'], 0, 10)], $this->store->ledgerEntries($property, $supplier['id'], 50)),
            'prices' => array_map(static fn (array $p): array => [
                'id' => $p['id'], 'item_id' => $p['item_id'], 'item_code' => $items[$p['item_id']]['code'] ?? '', 'item_name' => $items[$p['item_id']]['name'] ?? '', 'unit' => $p['unit'], 'unit_price_minor' => (int) $p['unit_price_minor'],
                'valid_from' => substr((string) $p['valid_from'], 0, 10), 'reason' => $p['reason'], 'created_by_name' => $names[$p['created_by']] ?? null, 'in_force' => ($inForce[$p['item_id'].'|'.$p['unit']] ?? null) === $p['id'],
            ], $prices),
            'ratings' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'score' => (int) $r['score'], 'aspect' => $r['aspect'], 'comment' => $r['comment'], 'ref_type' => $r['ref_type'], 'created_by_name' => $names[$r['created_by']] ?? null, 'created_at' => $utc($r['created_at']),
            ], $this->store->supplierRatings($property, $supplier['id'], 100)),
            'may' => $this->mayOf($property, $actorId),
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, array $fields): array
    {
        $this->authorizeManage($property, $actorId);
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,11}$/', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 12 letters, digits, dot, dash or underscore.', ['code']);
        }

        $clean = $this->cleanFields($name, $fields);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $clean): void {
            if (! $this->store->addSupplier($property, ['id' => $id, 'code' => $code, ...$clean], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A supplier with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'supplier.created', 'supplier', $id, null, ['code' => $code, 'name' => $clean['name'], 'payment_terms_days' => $clean['payment_terms_days']]));
        });

        return $this->shape($this->store->supplier($property, $id) ?? throw Refusal::notFound('Supplier not found.'));
    }

    /** @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, array $fields, bool $active, int $lock): array
    {
        $this->authorizeManage($property, $actorId);
        $before = $this->store->supplier($property, strtolower($id)) ?? throw Refusal::notFound('Supplier not found.');
        $clean = $this->cleanFields($name, $fields);

        $this->transactions->run(function () use ($property, $actorId, $before, $clean, $active, $lock): void {
            if (! $this->store->updateSupplier($property, $before['id'], $lock, [...$clean, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This supplier changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'supplier.updated', 'supplier', $before['id'], ['name' => $before['name'], 'payment_terms_days' => (int) $before['payment_terms_days'], 'is_active' => (bool) $before['is_active']], ['name' => $clean['name'], 'payment_terms_days' => $clean['payment_terms_days'], 'is_active' => $active]));
        });

        return $this->shape($this->store->supplier($property, $before['id']) ?? throw Refusal::notFound('Supplier not found.'));
    }

    /**
     * Adds a price from a date on. The price is for one unit of the item: the base unit or a unit it has a conversion for. It is never edited; to change it, add another.
     *
     * @return array<string, mixed>
     */
    public function addPrice(PropertyId $property, string $actorId, string $supplierId, string $itemId, string $unit, int $unitPriceMinor, string $validFrom, ?string $reason): array
    {
        $this->authorizeManage($property, $actorId);
        $supplier = $this->store->supplier($property, strtolower($supplierId)) ?? throw Refusal::notFound('Supplier not found.');
        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::invalid('Choose an item.', ['item_id']);
        $unit = strtoupper(trim($unit));

        if ($unit !== $item['base_unit'] && $this->inventory->currentUnit($property, $item['id'], $unit) === null) {
            throw Refusal::invalid('This item has no conversion for that unit.', ['unit']);
        }

        if ($unitPriceMinor < 0 || $unitPriceMinor > StockValue::MAX_UNIT_COST_MINOR) {
            throw Refusal::invalid('Give the price as a whole amount of at most 100,000,000.', ['unit_price_minor']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1 || ! checkdate((int) substr($validFrom, 5, 2), (int) substr($validFrom, 8, 2), (int) substr($validFrom, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['valid_from']);
        }

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($reason !== null && mb_strlen($reason) > 200) {
            throw Refusal::invalid('The reason is at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $supplier, $item, $unit, $unitPriceMinor, $validFrom, $reason): void {
            $this->store->addSupplierPrice($property, ['id' => $id, 'supplier_id' => $supplier['id'], 'item_id' => $item['id'], 'unit' => $unit, 'unit_price_minor' => $unitPriceMinor, 'valid_from' => $validFrom, 'reason' => $reason, 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_price.added', 'supplier', $supplier['id'], null, ['item' => $item['code'], 'unit' => $unit, 'unit_price_minor' => $unitPriceMinor, 'valid_from' => $validFrom], $reason));
        });

        return $this->show($property, $actorId, $supplier['id']);
    }

    /** @return array<string, mixed> */
    public function rate(PropertyId $property, string $actorId, string $supplierId, int $score, string $aspect, ?string $comment, ?string $refType = null, ?string $refId = null): array
    {
        $this->assertProperty($property);
        $may = $this->mayOf($property, $actorId);

        if (! $may['manage'] && ! $may['rate']) {
            throw Refusal::forbidden('This person may not rate suppliers.');
        }

        $supplier = $this->store->supplier($property, strtolower($supplierId)) ?? throw Refusal::notFound('Supplier not found.');

        if ($score < 1 || $score > 5) {
            throw Refusal::invalid('The score is from 1 to 5.', ['score']);
        }

        if (! in_array($aspect, self::ASPECTS, true)) {
            throw Refusal::invalid('Choose what the score is about.', ['aspect']);
        }

        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        if ($comment !== null && mb_strlen($comment) > 200) {
            throw Refusal::invalid('The comment is at most 200 characters.', ['comment']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $supplier, $score, $aspect, $comment, $refType, $refId): void {
            $this->store->addSupplierRating($property, ['id' => $id, 'supplier_id' => $supplier['id'], 'score' => $score, 'aspect' => $aspect, 'comment' => $comment, 'ref_type' => $refType, 'ref_id' => $refId, 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_rating.added', 'supplier', $supplier['id'], null, ['score' => $score, 'aspect' => $aspect]));
        });

        return $this->show($property, $actorId, $supplier['id']);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{name: string, contact_name: ?string, phone: ?string, email: ?string, address: ?string, tax_id: ?string, payment_terms_days: int, note: ?string}
     */
    private function cleanFields(string $name, array $fields): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw Refusal::invalid('Give the name, at most 120 characters.', ['name']);
        }

        $text = static fn (string $key, int $max): ?string => isset($fields[$key]) && trim((string) $fields[$key]) !== '' ? mb_substr(trim((string) $fields[$key]), 0, $max + 1) : null;
        $out = ['name' => $name, 'contact_name' => $text('contact_name', 80), 'phone' => $text('phone', 30), 'email' => $text('email', 120), 'address' => $text('address', 200), 'note' => $text('note', 200)];
        $limits = ['contact_name' => 80, 'phone' => 30, 'email' => 120, 'address' => 200, 'note' => 200];

        foreach ($limits as $key => $max) {
            if ($out[$key] !== null && mb_strlen($out[$key]) > $max) {
                throw Refusal::invalid('This field is at most '.$max.' characters.', [$key]);
            }
        }

        if ($out['email'] !== null && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw Refusal::invalid('Give a valid e-mail address.', ['email']);
        }

        $tax = isset($fields['tax_id']) ? preg_replace('/[^0-9]/', '', (string) $fields['tax_id']) : '';

        if ($tax !== '' && ! in_array(strlen($tax), [15, 16], true)) {
            throw Refusal::invalid('The tax number (NPWP) has 15 or 16 digits.', ['tax_id']);
        }

        $terms = (int) ($fields['payment_terms_days'] ?? 30);

        if ($terms < 0 || $terms > self::MAX_TERMS_DAYS) {
            throw Refusal::invalid('Payment terms are from 0 to '.self::MAX_TERMS_DAYS.' days.', ['payment_terms_days']);
        }

        return [...$out, 'tax_id' => $tax === '' ? null : $tax, 'payment_terms_days' => $terms];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function shape(array $s): array
    {
        return [
            'id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'contact_name' => $s['contact_name'], 'phone' => $s['phone'], 'email' => $s['email'], 'address' => $s['address'], 'tax_id' => $s['tax_id'],
            'payment_terms_days' => (int) $s['payment_terms_days'], 'note' => $s['note'], 'is_active' => (bool) $s['is_active'], 'lock_version' => (int) $s['lock_version'],
            'rating_avg' => isset($s['rating_avg']) && $s['rating_avg'] !== null ? round((float) $s['rating_avg'], 1) : null, 'rating_count' => (int) ($s['rating_count'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> the active items a price can be given for, with the units each can be priced in */
    private function itemChoices(PropertyId $property): array
    {
        $units = [];

        foreach ($this->inventory->unitVersions($property) as $u) {
            $units[$u['item_id']][$u['unit']] = $u['unit'];
        }

        return array_values(array_map(static fn (array $i): array => ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit'], 'units' => array_values($units[$i['id']] ?? [])], array_filter($this->inventory->items($property), static fn (array $i): bool => (bool) $i['is_active'])));
    }

    /** @return array{manage: bool, rate: bool} */
    private function mayOf(PropertyId $property, string $actorId): array
    {
        return ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property), 'rate' => $this->permissions->allowsInProperty($actorId, self::RATE_PERMISSION, $property)];
    }

    private function authorizeView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);
        $may = $this->mayOf($property, $actorId);

        if (! $may['manage'] && ! $may['rate'] && ! $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see suppliers.');
        }
    }

    private function authorizeManage(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not change suppliers.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
