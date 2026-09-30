<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

interface OneTimePassword
{
    public function generateSecret(): string;

    public function provisioningUri(string $secret, string $accountName): string;

    public function verify(string $secret, string $code): bool;
}
