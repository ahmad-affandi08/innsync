<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\ReportNotifier;
use App\Modules\Reporting\Application\ReportScheduleService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-RPT-004: a report that is built by itself at a set time, as each recipient, with a notice by e-mail that carries no figures. */
final class ReportScheduleHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private AdjustableClock $clock;

    private UserRecord $manager;

    private UserRecord $reader;

    private UserRecord $outsider;

    /** @var list<array{string, string, string}> */
    private array $mails = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['files.disk' => 'local', 'app.url' => 'https://hotel.example']);
        $this->clock = new AdjustableClock('2026-10-01 03:00:00');
        $this->app->instance(Clock::class, $this->clock);
        $test = $this;
        $this->app->instance(ReportNotifier::class, new class($test) implements ReportNotifier
        {
            public function __construct(private object $test) {}

            public function notify(string $address, string $subject, string $body): bool
            {
                $this->test->mailed([$address, $subject, $body]);

                return true;
            }
        });
        $this->createProperty(self::A, 'A');
        $this->reader = UserRecord::factory()->create();
        $this->grant($this->reader, self::A, [ReportService::VIEW_PERMISSION]);
        $this->outsider = UserRecord::factory()->create();
        $this->grant($this->outsider, self::A, []);
        $this->manager = UserRecord::factory()->create();
        $this->grant($this->manager, self::A, [ReportScheduleService::MANAGE_PERMISSION, ReportService::VIEW_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->enter($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    /** @param array{string, string, string} $mail */
    public function mailed(array $mail): void
    {
        $this->mails[] = $mail;
    }

    private function enter(UserRecord $user): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function body(array $over = []): array
    {
        return [...['name' => 'Payments every morning', 'report' => 'payments', 'params' => ['preset' => 'yesterday'], 'cadence' => 'daily', 'weekday' => null, 'month_day' => null, 'at_time' => '07:00', 'notify_email' => false, 'recipients' => [strtolower((string) $this->reader->getKey())]], ...$over];
    }

    private function drain(): void
    {
        $property = PropertyId::fromString(self::A);
        app(PropertyContext::class)->activate($property);

        foreach (DB::table('outbox_messages')->whereIn('event_type', ['reporting.export.finished', 'reporting.export.failed'])->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($property, (string) $id, 1);
        }
    }

    public function test_a_schedule_runs_at_its_time_asks_each_recipient_for_the_export_and_runs_once_per_time(): void
    {
        $v = $this->postJson('/reports/schedules', $this->body())->assertCreated();
        $v->assertJsonPath('schedules.0.next_run_at', '2026-10-02T00:00:00Z')->assertJsonPath('schedules.0.at_time', '07:00')->assertJsonPath('time_zone', 'Asia/Jakarta')->assertJsonPath('schedules.0.recipients.0.name', $this->reader->name);

        self::assertSame(0, Artisan::call('reports:run-schedules'));
        self::assertSame(0, DB::table('report_export_jobs')->count(), 'not due yet');

        $this->clock->advance('+22 hours');
        Artisan::call('reports:run-schedules');
        $job = DB::table('report_export_jobs')->first();
        self::assertSame([strtolower((string) $this->reader->getKey()), 'payments', 'queued'], [strtolower((string) $job->requested_by), $job->report, $job->status]);
        self::assertNotNull($job->schedule_id);
        self::assertSame('Scheduled report: Payments every morning', $job->purpose);
        self::assertSame(['preset' => 'yesterday'], json_decode((string) $job->params, true));
        self::assertSame(['2026-10-03T00:00:00Z'], [substr((string) DB::table('report_schedules')->value('next_run_at'), 0, 10).'T00:00:00Z']);

        // Running again before the next time asks for nothing more.
        Artisan::call('reports:run-schedules');
        self::assertSame(1, DB::table('report_export_jobs')->count());
        self::assertSame([1, 0], [(int) DB::table('report_schedule_runs')->value('queued'), (int) DB::table('report_schedule_runs')->value('skipped')]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report_schedule.ran')->count());

        // The system was down for days: one run when it is back, not one for each missed time.
        $this->clock->advance('+5 days');
        Artisan::call('reports:run-schedules');
        self::assertSame(2, DB::table('report_export_jobs')->count());
        self::assertSame(2, DB::table('report_schedule_runs')->count());
    }

    public function test_the_recipient_gets_the_file_built_as_them_and_an_e_mail_without_figures_when_asked(): void
    {
        $this->postJson('/reports/schedules', $this->body(['notify_email' => true]))->assertCreated();
        $this->clock->advance('+22 hours');
        Artisan::call('reports:run-schedules');
        Artisan::call('reports:run-exports');
        $job = DB::table('report_export_jobs')->first();
        self::assertSame('done', $job->status);
        $this->drain();

        self::assertCount(1, $this->mails);
        [$to, $subject, $body] = $this->mails[0];
        self::assertSame($this->reader->email, $to);
        self::assertStringContainsString('Payments every morning', $subject);
        self::assertStringContainsString('https://hotel.example/reports/exports', $body);
        self::assertStringNotContainsString('IDR', $body);

        $this->enter($this->reader);
        $this->get('/reports/exports')->assertInertia(fn (Assert $p) => $p->where('overview.jobs.0.status', 'done'));
        $this->get("/reports/exports/{$job->id}/download")->assertOk();

        // No e-mail is sent when the schedule does not ask for it.
        $this->enter($this->manager);
        $this->mails = [];
        DB::table('report_export_jobs')->where('id', $job->id)->update(['seen_at' => now()]);
    }

    public function test_a_recipient_who_may_no_longer_export_is_skipped_and_the_run_says_so(): void
    {
        $this->postJson('/reports/schedules', $this->body())->assertCreated();
        DB::table('user_role_assignments')->where('user_id', $this->reader->getKey())->update(['is_active' => false]);
        $this->clock->advance('+22 hours');
        Artisan::call('reports:run-schedules');

        self::assertSame(0, DB::table('report_export_jobs')->count());
        $run = DB::table('report_schedule_runs')->first();
        self::assertSame([0, 1], [(int) $run->queued, (int) $run->skipped]);
        self::assertStringContainsString('no longer', (string) $run->skipped_note);
        $this->get('/reports/schedules')->assertInertia(fn (Assert $p) => $p->where('overview.runs', fn ($runs) => count($runs) === 1));
    }

    public function test_a_schedule_is_checked_on_its_inputs_and_its_recipients_and_changed_only_at_the_version_seen(): void
    {
        $bad = fn (array $over, int $status = 422) => $this->postJson('/reports/schedules', $this->body($over))->assertStatus($status);
        $bad(['name' => '']);
        $bad(['report' => 'nonsense']);
        $bad(['cadence' => 'hourly']);
        $bad(['cadence' => 'weekly', 'weekday' => null]);
        $bad(['cadence' => 'monthly', 'month_day' => 31]);
        $bad(['at_time' => '25:00']);
        $bad(['params' => ['preset' => 'last-century']]);
        $bad(['recipients' => []]);
        $bad(['recipients' => ['01arz3ndektsv4rrffq69g5fzz']]);
        $bad(['recipients' => [strtolower((string) $this->outsider->getKey())]]);
        $bad(['report' => 'registrations', 'recipients' => [strtolower((string) $this->reader->getKey())]], 403);
        self::assertSame(0, DB::table('report_schedules')->count());

        $this->postJson('/reports/schedules', $this->body(['cadence' => 'weekly', 'weekday' => 1, 'at_time' => '08:30', 'report' => 'performance', 'params' => ['by' => 'month']]))->assertCreated()->assertJsonPath('schedules.0.params.by', 'month')->assertJsonPath('schedules.0.next_run_at', '2026-10-05T01:30:00Z');
        $schedule = DB::table('report_schedules')->first();
        $url = "/reports/schedules/{$schedule->id}";
        $this->postJson($url, [...$this->body(['name' => 'Renamed']), 'lock_version' => 5])->assertStatus(409);
        $this->postJson($url, [...$this->body(['name' => 'Renamed']), 'lock_version' => 0])->assertOk()->assertJsonPath('schedules.0.name', 'Renamed')->assertJsonPath('schedules.0.lock_version', 1);

        // Paused: it never runs and has no next time; resumed: the next time is worked out again.
        $this->postJson("{$url}/active", ['active' => false, 'lock_version' => 1])->assertOk()->assertJsonPath('schedules.0.next_run_at', null)->assertJsonPath('schedules.0.is_active', false);
        $this->clock->advance('+3 days');
        Artisan::call('reports:run-schedules');
        self::assertSame(0, DB::table('report_schedule_runs')->count());
        $this->postJson("{$url}/active", ['active' => true, 'lock_version' => 2])->assertOk()->assertJsonPath('schedules.0.is_active', true);
        self::assertNotNull(DB::table('report_schedules')->value('next_run_at'));
        self::assertSame(['report_schedule.created', 'report_schedule.updated', 'report_schedule.paused', 'report_schedule.resumed'], DB::table('audit_entries')->where('action', 'like', 'report_schedule.%')->orderBy('id')->pluck('action')->all());

        try {
            DB::table('report_schedule_runs')->insert(['id' => '01arz3ndektsv4rrffq69g5fr1', 'property_id' => self::A, 'schedule_id' => $schedule->id, 'due_at' => now(), 'ran_at' => now(), 'queued' => 0, 'skipped' => 0]);
            DB::table('report_schedule_runs')->update(['queued' => 9]);
            self::fail('A run was changed.');
        } catch (QueryException $e) {
            self::assertStringContainsString('a run of a schedule cannot be changed', $e->getMessage());
        }
    }

    public function test_only_whoever_may_schedule_reports_sees_or_changes_them(): void
    {
        $this->enter($this->reader);
        $this->get('/reports/schedules')->assertStatus(403);
        $this->postJson('/reports/schedules', $this->body())->assertStatus(403);
        self::assertSame(0, DB::table('report_schedules')->count());

        $this->enter($this->manager);
        $this->get('/reports/schedules')->assertOk()->assertInertia(fn (Assert $p) => $p->component('reporting/pages/schedules')->has('overview.members')->where('overview.may.manage', true));
    }
}
