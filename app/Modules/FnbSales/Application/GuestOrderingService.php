<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The point of sale for the guest self-service (see `GuestOrdering`). Every step is the same service a waiter uses, called as the guest self-service account, so the sold-out check, the price that holds,
 * the choices of a dish, the numbering, the audit and the order sent to the kitchen are exactly those of a waiter's order. A table's open bill takes the new lines; a room gets its own room service order,
 * promised in {@see self::ROOM_SERVICE_MINUTES} minutes (the kitchen and the board see it like any other).
 */
final readonly class GuestOrderingService implements GuestOrdering
{
    public const ROOM_SERVICE_MINUTES = 30;

    public function __construct(
        private SetupStore $setup,
        private BillStore $bills,
        private BillService $service,
        private RoomServiceService $roomService,
        private MinibarStore $orders,
        private BillPricing $pricing,
        private SystemActors $actors,
        private PropertyTimeZoneReader $zones,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private Clock $clock,
    ) {}

    public function table(PropertyId $property, string $tableId): ?array
    {
        $table = $this->setup->table($property, strtolower($tableId));

        if ($table === null || ! (bool) $table['is_active']) {
            return null;
        }

        $outlet = $this->setup->outlet($property, $table['outlet_id']);

        return $outlet === null || ! (bool) $outlet['is_active'] ? null : ['table_id' => $table['id'], 'code' => $table['code'], 'outlet_id' => $outlet['id'], 'outlet' => $outlet['name']];
    }

    public function roomOutlets(PropertyId $property): array
    {
        return array_values(array_map(static fn (array $o): array => ['id' => $o['id'], 'name' => $o['name']], array_filter($this->setup->outlets($property), static fn (array $o): bool => $o['kind'] === 'room_service' && (bool) $o['is_active'])));
    }

    public function tables(PropertyId $property): array
    {
        $out = [];

        foreach ($this->setup->outlets($property) as $o) {
            if (! (bool) $o['is_active']) {
                continue;
            }

            foreach ($this->setup->tables($property, $o['id']) as $t) {
                if ((bool) $t['is_active']) {
                    $out[] = ['id' => $t['id'], 'code' => $t['code'], 'name' => $o['name'], 'kind' => $o['kind']];
                }
            }
        }

        return $out;
    }

    public function menu(PropertyId $property, string $outletId, string $channel): array
    {
        $outlet = $this->setup->outlet($property, strtolower($outletId));

        if ($outlet === null || ! (bool) $outlet['is_active']) {
            return [];
        }

        return $this->service->orderMenu($property, $outlet['id'], $channel);
    }

    public function place(PropertyId $property, array $target, array $lines, ?string $note): array
    {
        $actor = $this->actors->guestSelfService($property, [FnbAccess::POS_OPERATE]);

        return $this->transactions->run(function () use ($property, $actor, $target, $lines, $note): array {
            if ($target['kind'] === 'table') {
                $billId = null;

                foreach ($this->bills->openBills($property, $target['outlet_id']) as $b) {
                    if ($b['table_id'] === $target['table_id']) {
                        $billId = (string) $b['id'];
                    }
                }

                $view = $billId === null
                    ? $this->service->open($property, $actor, $target['outlet_id'], $target['table_id'], null, 1, 'QR order', 'qr')
                    : $this->service->show($property, $actor, $billId);
            } else {
                $view = $this->roomOrder($property, $actor, $target, $note);
            }

            $billId = (string) $view['bill']['id'];
            $before = array_column($view['bill']['lines'], 'id');

            foreach ($lines as $l) {
                $view = $this->service->addLine($property, $actor, $billId, (int) $view['bill']['lock_version'], $l['item_id'], $l['variant_id'], $l['modifier_ids'], $l['quantity'], $l['note']);
            }

            $added = array_values(array_diff(array_column($view['bill']['lines'], 'id'), $before));
            $view = $this->service->send($property, $actor, $billId, (int) $view['bill']['lock_version']);
            $subtotal = 0;

            foreach ($view['bill']['lines'] as $l) {
                if (in_array($l['id'], $added, true)) {
                    $subtotal += (int) $l['line_total_minor'];
                }
            }

            return ['bill_id' => $billId, 'bill_number' => (string) $view['bill']['number'], 'line_ids' => $added, 'subtotal_minor' => $subtotal];
        });
    }

    public function progress(PropertyId $property, string $billId, array $lineIds): array
    {
        $bill = $this->bills->bill($property, strtolower($billId));

        if ($bill === null) {
            return [];
        }

        $out = [];

        foreach ($bill['lines'] as $l) {
            if (in_array($l['id'], $lineIds, true)) {
                $out[] = ['line_id' => $l['id'], 'name' => $l['item_name'], 'variant' => $l['variant_name'], 'quantity' => (int) $l['quantity'], 'status' => $l['status'], 'prep_status' => $l['prep_status'], 'total_minor' => (int) $l['line_total_minor']];
            }
        }

        return $out;
    }

    public function stateOf(PropertyId $property, string $billId): ?array
    {
        $bill = $this->bills->bill($property, strtolower($billId));

        if ($bill === null) {
            return null;
        }

        $order = $this->orders->orderOfBill($property, $bill['id']);

        return ['status' => (string) $bill['status'], 'room_service' => $order === null ? null : (string) $order['status']];
    }

    public function totalsOf(PropertyId $property, string $billId): ?array
    {
        $bill = $this->bills->bill($property, strtolower($billId));
        $outlet = $bill === null ? null : $this->setup->outlet($property, $bill['outlet_id']);

        if ($bill === null || $outlet === null) {
            return null;
        }

        $totals = $this->pricing->totals($property, $outlet, (string) $bill['business_date'], $bill['lines']);

        return ['total_minor' => $totals['total_minor'], 'subtotal_minor' => $totals['subtotal_minor'], 'service_charge_minor' => $totals['service_charge_minor'], 'tax_minor' => $totals['tax_minor'], 'currency' => $this->currencies->currencyOf($property)];
    }

    /**
     * @param  array{kind: string, outlet_id: string, table_id: string|null, room_id: string|null}  $target
     * @return array<string, mixed> the view of the bill
     */
    private function roomOrder(PropertyId $property, string $actor, array $target, ?string $note): array
    {
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $now = $this->clock->nowUtc();
        $local = $zone->localize($now);
        $promised = $local->modify('+'.self::ROOM_SERVICE_MINUTES.' minutes');

        // A promise past midnight is the last minute of the day: the board works on the day of the order.
        $time = $promised->format('Y-m-d') === $local->format('Y-m-d') ? $promised->format('H:i') : '23:59';

        if ($time <= $local->format('H:i')) {
            throw Refusal::stateConflict('Room service has closed for today.');
        }

        $placed = $this->roomService->place($property, $actor, $target['outlet_id'], (string) $target['room_id'], $time, 1, $note, 'qr');

        return $this->service->show($property, $actor, (string) $placed['bill_id']);
    }
}
