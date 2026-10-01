<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

interface ProviderRegistry
{
    /** @throws UnknownProvider */
    public function settings(string $provider): ProviderSettings;
}
