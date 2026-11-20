<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Once the response of a web request has been sent, moves what that request put in the outbox through a short, bounded pass of the same drain and worker the scheduler runs,
 * so a POS order reaches the kitchen screen in seconds instead of at the next cron tick. The cron stays as the safety net; a message is claimed once whichever of them gets it.
 * Off outside production (OUTBOX_AFTER_RESPONSE).
 */
final class AfterResponseDrain
{
    private static bool $registered = false;

    public static function request(): void
    {
        if (self::$registered || ! config('outbox.after_response') || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return;
        }

        self::$registered = true;

        app()->terminating(static function (): void {
            self::$registered = false;

            try {
                Artisan::call('outbox:drain', ['--limit' => 20]);
                Artisan::call('queue:work', [
                    'connection' => (string) config('outbox.queue_connection'),
                    '--queue' => (string) config('outbox.queue_name'),
                    '--stop-when-empty' => true,
                    '--sleep' => 1,
                    '--tries' => (int) config('outbox.max_attempts'),
                    '--timeout' => (int) config('outbox.job_timeout_seconds'),
                    '--max-time' => 8,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
