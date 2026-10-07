<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

interface MessagingSettings
{
    public function get(string $channel): ?ChannelConfig;

    /** @param array<string, string> $values */
    public function save(string $channel, string $provider, array $values, bool $enabled, string $actorId): void;

    public function disable(string $channel, string $actorId): void;

    public function recordTest(string $channel, bool $ok, ?string $error): void;
}
