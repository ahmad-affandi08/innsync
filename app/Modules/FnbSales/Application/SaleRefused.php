<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Offline\OfflineOutcome;
use RuntimeException;

/** Thrown inside the sale's transaction to roll everything back and hand the outcome to the foundation. */
final class SaleRefused extends RuntimeException
{
    public function __construct(public readonly OfflineOutcome $outcome)
    {
        parent::__construct('The offline sale was refused.');
    }
}
