<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class OutboxConsoleTest extends TestCase
{
    public function test_scheduler_uses_minutely_bounded_database_queue_drain(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = app(Schedule::class)->events();
        $commands = implode("\n", array_map(
            static fn ($event): string => (string) $event->command,
            $events,
        ));

        self::assertStringContainsString('outbox:drain', $commands);
        self::assertStringContainsString("queue:work 'database'", $commands);
        self::assertStringContainsString("--queue='outbox'", $commands);
        self::assertStringContainsString('--stop-when-empty', $commands);
        self::assertStringContainsString('--max-time=50', $commands);
        self::assertCount(2, array_filter(
            $events,
            static fn ($event): bool => $event->expression === '* * * * *'
                && $event->withoutOverlapping
                && (str_contains((string) $event->command, 'outbox:drain') || str_contains((string) $event->command, 'queue:work')),
        ));
        // Report exports asked for (FR-RPT-011) are built by their own minutely, non-overlapping run.
        self::assertCount(1, array_filter(
            $events,
            static fn ($event): bool => $event->expression === '* * * * *' && $event->withoutOverlapping && str_contains((string) $event->command, 'reports:run-exports'),
        ));
    }

    public function test_drain_command_rejects_unbounded_batch_size(): void
    {
        $this->artisan('outbox:drain', ['--limit' => '1001'])
            ->expectsOutputToContain('between 1 and 1000')
            ->assertExitCode(2);
    }
}
