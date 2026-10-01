<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Application\Observability\Health\AlertNotifier;
use App\Shared\Application\Observability\Health\EvaluateAlerts;
use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthCheckRegistry;
use App\Shared\Application\Observability\Health\HealthReport;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Application\Observability\Health\RunHealthChecks;
use App\Shared\Infrastructure\Observability\Health\ErrorRate;
use App\Shared\Infrastructure\Observability\Health\FailedJobsCheck;
use App\Shared\Infrastructure\Observability\Health\OutboxBacklogCheck;
use App\Shared\Infrastructure\Observability\Health\SchedulerHeartbeat;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class HealthAlertingTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_alert_lifecycle_raises_once_escalates_and_resolves(): void
    {
        $notifier = new RecordingNotifier;
        $this->app->instance(AlertNotifier::class, $notifier);
        $evaluate = app(EvaluateAlerts::class);

        $evaluate->execute($this->report(HealthResult::degraded('slow', ['n' => 1])));
        $evaluate->execute($this->report(HealthResult::degraded('slow', ['n' => 2])));
        self::assertSame(['raised'], $notifier->transitions);
        $this->assertDatabaseHas('operational_alerts', ['open_key' => 'probe', 'occurrences' => 2]);

        $evaluate->execute($this->report(HealthResult::down('broken')));
        self::assertSame(['raised', 'escalated'], $notifier->transitions);
        $this->assertDatabaseHas('operational_alerts', ['open_key' => 'probe', 'severity' => 'down', 'occurrences' => 3]);

        $evaluate->execute($this->report(HealthResult::ok('fine')));
        self::assertSame(['raised', 'escalated', 'resolved'], $notifier->transitions);
        self::assertSame(0, DB::table('operational_alerts')->whereNotNull('open_key')->count());
        self::assertNotNull(DB::table('operational_alerts')->value('resolved_at'));

        $evaluate->execute($this->report(HealthResult::degraded('again')));
        self::assertSame(2, DB::table('operational_alerts')->where('alert_key', 'probe')->count());
    }

    public function test_only_one_open_alert_per_key_is_possible(): void
    {
        $row = fn () => [
            'id' => strtolower((string) Str::ulid()), 'alert_key' => 'probe', 'open_key' => 'probe',
            'severity' => 'down', 'summary' => 's', 'context' => '{}', 'occurrences' => 1,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ];
        DB::table('operational_alerts')->insert($row());

        $this->expectException(QueryException::class);
        DB::table('operational_alerts')->insert($row());
    }

    public function test_a_throwing_check_becomes_down_instead_of_failing_the_run(): void
    {
        $this->app->instance(HealthCheckRegistry::class, new class implements HealthCheckRegistry
        {
            public function all(): array
            {
                return [new class implements HealthCheck
                {
                    public function name(): string
                    {
                        return 'exploding';
                    }

                    public function check(): HealthResult
                    {
                        throw new RuntimeException('password=hunter2');
                    }
                }];
            }
        });

        $report = app(RunHealthChecks::class)->execute();

        self::assertSame(HealthStatus::Down, $report->status());
        self::assertStringNotContainsString('hunter2', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_default_registry_runs_every_builtin_signal(): void
    {
        $report = app(RunHealthChecks::class)->execute();

        self::assertEqualsCanonicalizing(
            ['database', 'scheduler', 'failed_jobs', 'outbox_backlog', 'storage_capacity', 'error_rate', 'backup', 'sync_backlog', 'privacy_requests'],
            array_keys($report->results),
        );
        self::assertSame(HealthStatus::Ok, $report->results['database']->status);
    }

    public function test_failed_jobs_thresholds(): void
    {
        config(['observability.failed_jobs_degraded_at' => 1, 'observability.failed_jobs_down_at' => 3]);
        $check = app(FailedJobsCheck::class);
        self::assertSame(HealthStatus::Ok, $check->check()->status);

        foreach ([1, 3] as $total) {
            while (DB::table('failed_jobs')->count() < $total) {
                DB::table('failed_jobs')->insert([
                    'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'outbox',
                    'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
                ]);
            }
            self::assertSame($total === 1 ? HealthStatus::Degraded : HealthStatus::Down, $check->check()->status);
        }
    }

    public function test_outbox_backlog_detects_stalled_delivery_and_dead_letters(): void
    {
        $property = new PropertyRecord(['name' => 'P', 'timezone' => 'Asia/Jakarta', 'currency_code' => 'IDR']);
        $property->id = self::PROPERTY;
        $property->save();
        $check = app(OutboxBacklogCheck::class);
        self::assertSame(HealthStatus::Ok, $check->check()->status);

        $this->outboxRow('pending', now()->subMinutes(10));
        self::assertSame(HealthStatus::Degraded, $check->check()->status);

        DB::table('outbox_messages')->delete();
        $this->outboxRow('pending', now()->subHours(2));
        self::assertSame(HealthStatus::Down, $check->check()->status);

        DB::table('outbox_messages')->delete();
        $this->outboxRow('dead_letter', now()->subHours(2));
        $result = $check->check();
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame(1, $result->context['dead_letters']);
    }

    public function test_scheduler_heartbeat_goes_stale(): void
    {
        $heartbeat = app(SchedulerHeartbeat::class);
        self::assertSame(HealthStatus::Degraded, $heartbeat->check()->status);

        $heartbeat->record();
        self::assertSame(HealthStatus::Ok, $heartbeat->check()->status);

        $this->travel(10)->minutes();
        self::assertSame(HealthStatus::Down, $heartbeat->check()->status);
    }

    public function test_error_rate_counts_only_reported_exceptions(): void
    {
        config(['observability.error_rate_degraded_count' => 2, 'observability.error_rate_down_count' => 4]);
        $rate = app(ErrorRate::class);

        $this->report_exception(new RuntimeException('expected'), 1);
        self::assertSame(HealthStatus::Ok, $rate->check()->status);
        $this->report_exception(new RuntimeException('defect'), 1);
        self::assertSame(HealthStatus::Degraded, $rate->check()->status);
        $this->report_exception(new RuntimeException('defect'), 2);
        self::assertSame(HealthStatus::Down, $rate->check()->status);

        $this->travel(10)->minutes();
        self::assertSame(HealthStatus::Ok, $rate->check()->status);
    }

    public function test_commands_report_status_and_raise_alerts(): void
    {
        $this->artisan('health:heartbeat')->assertSuccessful();
        $this->artisan('health:check --json')->assertSuccessful();
        $this->artisan('health:alerts')->assertSuccessful();
        self::assertSame(0, DB::table('operational_alerts')->where('alert_key', 'failed_jobs')->count());

        config(['observability.failed_jobs_down_at' => 1]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'outbox',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);
        $this->artisan('health:check')->assertFailed();
        $this->artisan('health:alerts')->assertSuccessful();
        $this->assertDatabaseHas('operational_alerts', ['open_key' => 'failed_jobs', 'severity' => 'down']);
    }

    private function report_exception(\Throwable $e, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            report($e);
        }
    }

    private function report(HealthResult $result): HealthReport
    {
        return new HealthReport(['probe' => $result]);
    }

    private function outboxRow(string $status, \DateTimeInterface $availableAt): void
    {
        $dead = $status === 'dead_letter';
        DB::table('outbox_messages')->insert([
            'id' => strtolower((string) Str::ulid()), 'property_id' => self::PROPERTY, 'event_type' => 'x.y',
            'aggregate_id' => strtolower((string) Str::ulid()), 'payload_version' => 1, 'encrypted_payload' => 'x',
            'correlation_id' => strtolower((string) Str::ulid()), 'status' => $status, 'attempt_count' => 0,
            'requeue_count' => 0, 'available_at' => $availableAt, 'dead_lettered_at' => $dead ? now() : null,
            'last_error_type' => $dead ? 'E' : null, 'last_error_fingerprint' => $dead ? str_repeat('a', 64) : null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

final class RecordingNotifier implements AlertNotifier
{
    /** @var list<string> */
    public array $transitions = [];

    public function notify(string $transition, string $key, HealthResult $result): void
    {
        $this->transitions[] = $transition;
    }
}
