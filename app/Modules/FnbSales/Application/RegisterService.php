<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the register keeps on the device so it can sell with no network (FR-FBS-010): the tables of an outlet, its menu with the choices of every dish and the price that holds
 * for each way of selling now. The device shows the guest these prices and sends them with the sale, so the server can tell a price that changed meanwhile from one that did not.
 */
final readonly class RegisterService
{
    public function __construct(private SetupStore $setup, private BillService $bills, private PaymentStore $payments, private FnbAccess $access, private PropertyCurrencyReader $currencies) {}

    /** @return array<string, mixed> */
    public function view(PropertyId $property, string $actorId, ?string $outletId): array
    {
        $this->access->require($property, $actorId, FnbAccess::POS_OPERATE, 'This person may not take orders.');
        $outlets = array_values(array_filter($this->setup->outlets($property), static fn (array $o): bool => (bool) $o['is_active']));
        $selected = null;

        foreach ($outlets as $o) {
            if ($outletId === null || $o['id'] === strtolower($outletId)) {
                $selected = $o;

                break;
            }
        }

        if ($outletId !== null && $selected === null) {
            throw Refusal::notFound('Outlet not found.');
        }

        $tables = [];
        $menu = [];

        if ($selected !== null) {
            foreach ($this->setup->tables($property, $selected['id']) as $t) {
                if ((bool) $t['is_active']) {
                    $tables[] = ['id' => $t['id'], 'code' => $t['code'], 'area' => $t['area'], 'seats' => (int) $t['seats']];
                }
            }

            $dine = $this->bills->orderMenu($property, $selected['id'], 'dine_in');
            $take = $this->bills->orderMenu($property, $selected['id'], 'takeaway');
            $takeBy = [];

            foreach ($take as $c) {
                foreach ($c['items'] as $i) {
                    $takeBy[$i['id']] = $i;
                }
            }

            foreach ($dine as $c) {
                $items = [];

                foreach ($c['items'] as $i) {
                    $t = $takeBy[$i['id']] ?? $i;
                    $variantTake = [];

                    foreach ($t['variants'] as $v) {
                        $variantTake[$v['id']] = $v['price_minor'];
                    }

                    $items[] = [
                        'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'is_available' => $i['is_available'], 'groups' => $i['groups'],
                        'prices' => ['dine_in' => $i['price_minor'], 'takeaway' => $t['price_minor']],
                        'variants' => array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'prices' => ['dine_in' => $v['price_minor'], 'takeaway' => $variantTake[$v['id']] ?? $v['price_minor']]], $i['variants']),
                    ];
                }

                $menu[] = ['id' => $c['id'], 'name' => $c['name'], 'items' => $items];
            }
        }

        return [
            'currency' => $this->currencies->currencyOf($property),
            'outlets' => array_map(static fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name']], $outlets),
            'outlet' => $selected === null ? null : ['id' => $selected['id'], 'code' => $selected['code'], 'name' => $selected['name']],
            'tables' => $tables, 'menu' => $menu,
            'cashier' => $this->access->may($property, $actorId, FnbAccess::CASHIER_OPERATE),
            'shift_open' => $this->payments->openShiftOf($property, strtolower($actorId)) !== null,
        ];
    }
}
