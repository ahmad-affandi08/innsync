<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/** What every change of a bill starts with: the bill locked for the transaction, open, and at the version the person saw (FR-FBS-012). */
final readonly class BillGuard
{
    public function __construct(private BillStore $bills, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function open(PropertyId $property, string $id, int $lock): array
    {
        $this->bills->lockBill($property, $id);
        $bill = $this->bills->bill($property, $id) ?? throw Refusal::notFound('Bill not found.');

        if ($bill['status'] !== 'open') {
            throw Refusal::stateConflict('This bill is not open any more.');
        }

        if ((int) $bill['lock_version'] !== $lock) {
            throw Refusal::stateConflict('This bill was changed on another device. Reload it and check it.');
        }

        return $bill;
    }

    public function touch(PropertyId $property, string $id, int $lock): void
    {
        if (! $this->bills->touchBill($property, $id, $lock, $this->clock->nowUtc())) {
            throw Refusal::stateConflict('This bill was changed on another device. Reload it and check it.');
        }
    }
}
