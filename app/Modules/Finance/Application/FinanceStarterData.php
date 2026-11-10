<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Setup\StarterData;
use App\Shared\Domain\Tenancy\PropertyId;

/** What Finance starts a property with: the usual expense accounts of a hotel in Indonesia. */
final readonly class FinanceStarterData implements StarterData
{
    public function __construct(private ExpenseAccountService $accounts) {}

    public function parts(): array
    {
        return [
            ['module' => 'finance', 'label' => 'expense accounts', 'install' => fn (PropertyId $property, string $actorId): int => count($this->accounts->baseline($property, $actorId))],
        ];
    }
}
