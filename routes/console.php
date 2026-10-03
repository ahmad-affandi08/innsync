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

Schedule::command('reports:run-exports')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('maintenance:escalate')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('maintenance:preventive')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::command('health:heartbeat')->everyMinute();

Schedule::command('health:alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('backup:run')
    ->dailyAt((string) config('backup.run_at'))
    ->withoutOverlapping(180);

if (config('backup.restore_test_database')) {
    Schedule::command('backup:verify')
        ->weeklyOn(0, (string) config('backup.verify_at'))
        ->withoutOverlapping(180);
}

Schedule::command('retention:purge')
    ->dailyAt((string) config('retention.purge_at'))
    ->withoutOverlapping(120);
