<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the dishes sold in a period, for the departments that judge the menu (the kitchen, FR-KIT-012). Only bills that were settled count, never a refunded or cancelled one,
 * and a line that was voided or removed never counts. The revenue is net of discounts and of the service charge and the tax a bill carried. The caller has checked its own permission.
 */
interface MenuSales
{
    /**
     * @return list<array{item_id: string, code: string, name: string, category: string, outlet_id: string, outlet: string, portions: int, net_minor: int, discount_minor: int}> every dish sold between two business dates (both included), in no order
     */
    public function soldBetween(PropertyId $property, string $from, string $to): array;
}
