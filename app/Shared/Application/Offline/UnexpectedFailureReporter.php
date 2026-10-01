<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use Throwable;

/** Reports an unexpected handler failure to the error pipeline without failing the whole batch. */
interface UnexpectedFailureReporter
{
    public function report(Throwable $failure): void;
}
