<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Offline\UnexpectedFailureReporter;
use Throwable;

final class ReportingFailureReporter implements UnexpectedFailureReporter
{
    public function report(Throwable $failure): void
    {
        report($failure);
    }
}
