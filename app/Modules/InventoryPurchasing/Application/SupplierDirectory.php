<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** What other departments may know of the suppliers of purchasing (FR-MTC-015): who is in use, to ask for a quotation or give work to. Purchasing owns the suppliers. */
interface SupplierDirectory
{
    /** @return list<array{id: string, code: string, name: string}> the suppliers in use, by name */
    public function active(PropertyId $property): array;

    /** @return array{id: string, code: string, name: string, is_active: bool}|null */
    public function find(PropertyId $property, string $id): ?array;
}
