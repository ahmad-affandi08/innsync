<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

interface MessageTransport
{
    /** @return string|null null when the provider accepted the message, otherwise a short reason that carries no secret */
    public function deliver(ChannelConfig $config, string $to, string $subject, string $message): ?string;
}
