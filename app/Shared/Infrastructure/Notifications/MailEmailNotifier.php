<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifications;

use App\Shared\Application\Notifications\EmailNotifier;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends the notice by the mailer the installation configured (`MAIL_MAILER`; the log mailer until a provider is chosen). A failure is reported and answered with false. */
final readonly class MailEmailNotifier implements EmailNotifier
{
    public function notify(string $address, string $subject, string $body): bool
    {
        try {
            Mail::raw($body, static function ($message) use ($address, $subject): void {
                $message->to($address)->subject($subject);
            });

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
