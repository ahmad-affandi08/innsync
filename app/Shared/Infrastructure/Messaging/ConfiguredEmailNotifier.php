<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessageTransport;
use App\Shared\Application\Messaging\MessagingSettings;
use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Infrastructure\Notifications\MailEmailNotifier;

/** Sends email through the provider set on screen; when none is set up (or it is switched off) the server's own mail settings are used as before. */
final readonly class ConfiguredEmailNotifier implements EmailNotifier
{
    public function __construct(private MessagingSettings $settings, private MessageTransport $transport, private MailEmailNotifier $fallback) {}

    public function notify(string $address, string $subject, string $body): bool
    {
        $config = $this->settings->get('email');

        if ($config === null || ! $config->enabled) {
            return $this->fallback->notify($address, $subject, $body);
        }

        return $this->transport->deliver($config, $address, $subject, $body) === null;
    }
}
