<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

final class ConfigureStructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter);
            $handler->pushProcessor(new RedactSensitiveLogContext);
        }
    }
}
