<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessageTransport;
use App\Shared\Application\Messaging\MessagingSettings;
use App\Shared\Application\Notifications\WhatsAppNotifier;

final readonly class ConfiguredWhatsAppNotifier implements WhatsAppNotifier
{
    public function __construct(private MessagingSettings $settings, private MessageTransport $transport) {}

    public function notify(string $phone, string $message): bool
    {
        $config = $this->settings->get('whatsapp');

        return $config !== null && $config->enabled && $this->transport->deliver($config, $phone, '', $message) === null;
    }
}
