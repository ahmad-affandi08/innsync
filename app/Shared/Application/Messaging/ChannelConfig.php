<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

/** What an installation chose for one channel. `values` holds the provider's fields including secrets; it never leaves the server. */
final readonly class ChannelConfig
{
    /** @param array<string, string> $values */
    public function __construct(
        public string $channel,
        public string $provider,
        public array $values,
        public bool $enabled,
        public ?string $lastTestAt = null,
        public ?bool $lastTestOk = null,
        public ?string $lastTestError = null,
    ) {}
}
