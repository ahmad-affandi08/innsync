<?php

declare(strict_types=1);

namespace App\Shared\Application\Notifications;

/** Sends a short text to a phone through the WhatsApp provider the installation set up on screen. False when none is set up or the provider refused; never throws. */
interface WhatsAppNotifier
{
    public function notify(string $phone, string $message): bool;
}
