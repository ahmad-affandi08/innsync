<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ReportNotifier;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends the notice by the mailer the installation configured (`MAIL_MAILER`; the log mailer until a provider is chosen). A failure is reported, never thrown: a notice that cannot be sent must not undo the report. */
final readonly class MailReportNotifier implements ReportNotifier
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
