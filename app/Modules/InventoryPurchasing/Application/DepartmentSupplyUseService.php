<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

final readonly class DepartmentSupplyUseService implements DepartmentSupplyUse
{
    public function __construct(private InventoryStore $inventory, private StockPoster $poster, private StaffDirectory $staff, private TransactionRunner $transactions, private PropertyContext $property) {}

    public function items(PropertyId $property, string $department): array
    {
        $this->assertProperty($property);

        return array_values(array_map(
            static fn (array $i): array => ['id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'base_unit' => $i['base_unit']],
            array_filter($this->inventory->items($property), static fn (array $i): bool => (bool) $i['is_active'] && $i['department'] === $department),
        ));
    }

    public function locations(PropertyId $property): array
    {
        $this->assertProperty($property);

        return array_values(array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name']], array_filter($this->inventory->locations($property), static fn (array $l): bool => (bool) $l['is_active'])));
    }

    public function use(PropertyId $property, string $actorId, string $department, string $itemId, string $locationId, string $unit, string $quantity, ?string $note): array
    {
        $this->assertProperty($property);

        if (! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department of the list.', ['department']);
        }

        $qty = StockQuantity::parse($quantity);

        if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
            throw Refusal::invalid('Give the quantity as a number above zero, with at most three decimals.', ['quantity']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::invalid('Choose an item.', ['item_id']);
        $location = $this->inventory->location($property, strtolower($locationId)) ?? throw Refusal::invalid('Choose a location.', ['location_id']);

        if (! (bool) $item['is_active'] || ! (bool) $location['is_active'] || $item['department'] !== $department) {
            throw Refusal::invalid('Choose an item of this department that is in use, and a location that is in use.', ['item_id']);
        }

        $unit = strtoupper(trim($unit));
        $moved = $this->transactions->run(fn (): array => $this->poster->post($property, $actorId, $item, $location, 'issue', $unit, $qty, $department, null, $note, null, null, null, null, false));

        return ['id' => $moved['movement']['id'], 'balance_milli' => $this->inventory->balanceOf($property, $item['id'], $location['id'])];
    }

    public function recent(PropertyId $property, string $department, int $limit): array
    {
        $this->assertProperty($property);
        $rows = $this->inventory->issuesOfDepartment($property, $department, $limit);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'posted_by'))));

        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'item_code' => $r['item_code'], 'item_name' => $r['item_name'], 'location' => $r['location_name'], 'unit' => $r['unit'], 'quantity_milli' => abs((int) $r['unit_qty_milli']), 'note' => $r['note'],
            'by' => $names[$r['posted_by']] ?? '', 'business_date' => substr((string) $r['business_date'], 0, 10), 'at' => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ], $rows);
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
