<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

interface OfflineHandlerRegistry
{
    public function find(string $type): ?OfflineOperationHandler;
}
