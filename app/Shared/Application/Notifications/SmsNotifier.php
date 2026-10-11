<?php

declare(strict_types=1);

namespace App\Shared\Application\Notifications;

/** Sends a short text to a phone as an SMS through the provider the installation set up on screen. False when none is set up or the provider refused; never throws. */
interface SmsNotifier
{
    public function notify(string $phone, string $message): bool;
}
