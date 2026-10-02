<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * Purchasing reports (FR-PUR-009, FR-PUR-010). Purchases are what was received (accepted quantity at the order price, before tax) less what went back,
 * by department, supplier and item, in base-unit quantity and in money, for a period of business dates. The delivery report shows, per supplier, how
 * many orders came on time, how late the late ones were, how much of what was ordered arrived and how much of what arrived was refused. These are read
 * models over the receipts, returns and orders; they change nothing.
 */
final readonly class PurchasingReportService
{
    public function __construct(
        private PurchasingStore $store,
        private InventoryStore $inventory,
        private PurchasingAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function purchases(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $supplierId, ?string $department): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::REPORT_VIEW, 'This person may not see purchasing reports.');
        [$from, $to] = $this->period($property, $from, $to);

        if ($department !== null && $department !== '' && ! in_array($department, [...InventoryCatalogService::DEPARTMENTS, 'none'], true)) {
            throw Refusal::invalid('Choose a department from the list.', ['department']);
        }

        $items = array_column($this->inventory->items($property), null, 'id');
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $rows = [];
        $total = ['received_value' => 0, 'returned_value' => 0];

        foreach ($this->store->purchaseFlows($property, $from, $to) as $f) {
            $dept = $f['department'] === '' ? 'none' : $f['department'];

            if (($supplierId !== null && $supplierId !== '' && $f['supplier_id'] !== $supplierId) || ($department !== null && $department !== '' && $dept !== $department)) {
                continue;
            }

            $received = (int) $f['received_value'];
            $returned = (int) $f['returned_value'];
            $total['received_value'] += $received;
            $total['returned_value'] += $returned;
            $rows[] = [
                'department' => $dept, 'supplier_id' => $f['supplier_id'], 'supplier_name' => $suppliers[$f['supplier_id']]['name'] ?? '', 'item_id' => $f['item_id'], 'item_code' => $items[$f['item_id']]['code'] ?? '', 'item_name' => $items[$f['item_id']]['name'] ?? '',
                'base_unit' => $items[$f['item_id']]['base_unit'] ?? '', 'received_base_milli' => (int) $f['received_base'], 'returned_base_milli' => (int) $f['returned_base'], 'net_base_milli' => (int) $f['received_base'] - (int) $f['returned_base'],
                'received_value_minor' => $received, 'returned_value_minor' => $returned, 'net_value_minor' => $received - $returned,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['department'], $a['supplier_name'], $a['item_code']] <=> [$b['department'], $b['supplier_name'], $b['item_code']]);

        return [
            'currency' => $this->currency->currencyOf($property), 'from' => $from, 'to' => $to, 'rows' => $rows,
            'total_received_minor' => $total['received_value'], 'total_returned_minor' => $total['returned_value'], 'total_net_minor' => $total['received_value'] - $total['returned_value'],
            'departments' => [...InventoryCatalogService::DEPARTMENTS, 'none'], 'suppliers' => array_values(array_map(static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name']], $suppliers)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliveries(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->access->require($property, $actorId, PurchasingAccess::REPORT_VIEW, 'This person may not see purchasing reports.');
        [$from, $to] = $this->period($property, $from, $to);
        $suppliers = array_column($this->store->suppliers($property), null, 'id');
        $today = $this->businessDate->current($property)->toString();
        $per = [];
        $orders = [];

        foreach ($this->store->deliveryFlows($property, $from, $to) as $o) {
            $expected = $o['expected_date'] === null ? null : substr((string) $o['expected_date'], 0, 10);
            $last = substr((string) $o['last_on'], 0, 10);
            $late = $expected === null ? null : max(0, (int) (new DateTimeImmutable($expected))->diff(new DateTimeImmutable($last))->format('%r%a'));
            $sid = $o['supplier_id'];
            $per[$sid] ??= ['supplier_id' => $sid, 'supplier_name' => $suppliers[$sid]['name'] ?? '', 'orders' => 0, 'dated' => 0, 'on_time' => 0, 'days_late' => 0, 'late_orders' => 0, 'ordered' => 0, 'accepted' => 0, 'refused' => 0, 'receipts' => 0];
            $per[$sid]['orders']++;
            $per[$sid]['receipts'] += (int) $o['receipts'];
            $per[$sid]['ordered'] += (int) $o['ordered'];
            $per[$sid]['accepted'] += (int) $o['accepted'];
            $per[$sid]['refused'] += (int) $o['refused'];

            if ($expected !== null) {
                $per[$sid]['dated']++;
                $late === 0 ? $per[$sid]['on_time']++ : [$per[$sid]['late_orders']++, $per[$sid]['days_late'] += (int) $late];
            }

            $orders[] = [
                'id' => $o['id'], 'number' => $o['number'], 'supplier_name' => $suppliers[$sid]['name'] ?? '', 'status' => $o['status'], 'expected_date' => $expected, 'first_received_on' => substr((string) $o['first_on'], 0, 10), 'last_received_on' => $last,
                'days_late' => $late, 'ordered_milli' => (int) $o['ordered'], 'accepted_milli' => (int) $o['accepted'], 'refused_milli' => (int) $o['refused'], 'receipts' => (int) $o['receipts'],
                'complete' => in_array($o['status'], ['received', 'closed'], true), 'overdue' => $expected !== null && $expected < $today && ! in_array($o['status'], ['received', 'closed', 'cancelled'], true),
            ];
        }

        $rate = static fn (int $part, int $whole): ?int => $whole === 0 ? null : StockValue::mulDiv($part, 10000, $whole);
        $suppliersOut = array_values(array_map(static fn (array $p): array => [
            'supplier_id' => $p['supplier_id'], 'supplier_name' => $p['supplier_name'], 'orders' => $p['orders'], 'receipts' => $p['receipts'], 'dated_orders' => $p['dated'], 'on_time_orders' => $p['on_time'], 'late_orders' => $p['late_orders'],
            'on_time_bp' => $rate($p['on_time'], $p['dated']), 'average_days_late' => $p['late_orders'] === 0 ? 0 : round($p['days_late'] / $p['late_orders'], 1), 'fill_bp' => $rate($p['accepted'], $p['ordered']), 'refusal_bp' => $rate($p['refused'], $p['accepted'] + $p['refused']),
        ], $per));
        usort($suppliersOut, static fn (array $a, array $b): int => strcmp($a['supplier_name'], $b['supplier_name']));
        usort($orders, static fn (array $a, array $b): int => [$a['supplier_name'], $a['number']] <=> [$b['supplier_name'], $b['number']]);

        return ['from' => $from, 'to' => $to, 'suppliers' => $suppliersOut, 'orders' => $orders];
    }

    /** @return array{0: string, 1: string} */
    private function period(PropertyId $property, ?string $from, ?string $to): array
    {
        $today = $this->businessDate->current($property)->toString();
        $to = $to === null || $to === '' ? $today : $to;
        $from = $from === null || $from === '' ? substr($to, 0, 8).'01' : $from;

        foreach (['from' => $from, 'to' => $to] as $field => $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw Refusal::invalid('Give the date as year-month-day.', [$field]);
            }
        }

        if ($from > $to) {
            throw Refusal::invalid('The period ends before it starts.', ['to']);
        }

        return [$from, $to];
    }
}
