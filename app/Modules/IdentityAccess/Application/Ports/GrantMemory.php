<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

/** The short memory of permission answers kept for one web request: switched on when the request starts and thrown away when it ends. */
interface GrantMemory
{
    public function start(): void;

    public function stop(): void;
}
