<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration;

use App\Shared\Application\Integration\ProviderRegistry;
use App\Shared\Application\Integration\ProviderSettings;
use App\Shared\Application\Integration\UnknownProvider;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class ConfiguredProviderRegistry implements ProviderRegistry
{
    public function settings(string $provider): ProviderSettings
    {
        $providers = (array) config('integrations.providers');

        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider) !== 1 || ! isset($providers[$provider]) || ! is_array($providers[$provider])) {
            throw UnknownProvider::for($provider);
        }

        $defaults = (array) config('integrations.defaults');
        $config = $providers[$provider] + $defaults;

        return new ProviderSettings(
            $provider,
            PropertyId::fromString((string) $config['property_id']),
            (int) $config['connect_timeout_seconds'],
            (int) $config['read_timeout_seconds'],
            (int) $config['failure_threshold'],
            (int) $config['open_seconds'],
            array_values(array_filter(array_map('strval', (array) ($config['webhook_secrets'] ?? [])), static fn (string $secret): bool => $secret !== '')),
            isset($config['webhook_protocol']) ? (string) $config['webhook_protocol'] : null,
        );
    }
}
