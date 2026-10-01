<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:drain')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('queue:work', [
    (string) config('outbox.queue_connection'),
    '--queue' => (string) config('outbox.queue_name'),
    '--stop-when-empty',
    '--sleep' => 1,
    '--tries' => (int) config('outbox.max_attempts'),
    '--timeout' => (int) config('outbox.job_timeout_seconds'),
    '--max-time' => (int) config('outbox.worker_max_time_seconds'),
])
    ->everyMinute()
    ->withoutOverlapping(2);
