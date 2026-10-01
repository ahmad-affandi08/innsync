<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Application\Observability\SensitiveDataGuard;
use Monolog\LogRecord;

final class RedactSensitiveLogContext
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: SensitiveDataGuard::redact($record->context));
    }
}
