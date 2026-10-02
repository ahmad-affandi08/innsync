<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Reporting\Application\ExportJobRepository;
use App\Modules\Reporting\Application\ExportJobService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-RPT-011: a large export built in the background with a status, a private file of the person who asked and a notice when it is ready. */
final class ExportJobTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();
        config(['files.disk' => 'local']);
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('export-checkin-0001'));
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function exports(): ExportJobService
    {
        return app(ExportJobService::class);
    }

    private function refused(callable $do, int $status): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    public function test_a_request_needs_the_right_to_export_that_report_and_a_purpose_for_personal_data(): void
    {
        $this->refused(fn () => $this->exports()->request($this->property(), $this->analystId, 'nonsense', [], null), 422);
        $this->refused(fn () => $this->exports()->request($this->property(), $this->managerId, 'payments', [], null), 403);
        $this->refused(fn () => $this->exports()->request($this->property(), $this->registrarId, 'payments', [], null), 403);
        // The analyst reads reports but may not export guests.
        $this->refused(fn () => $this->exports()->request($this->property(), $this->analystId, 'registrations', [], 'Audit'), 403);
        $this->refused(fn () => $this->exports()->request($this->property(), $this->registrarId, 'registrations', [], null), 422);
        $this->refused(fn () => $this->exports()->request($this->property(), $this->registrarId, 'registrations', [], str_repeat('x', 301)), 422);
        $this->refused(fn () => $this->exports()->request($this->property(), $this->analystId, 'payments', ['from' => ['2026-10-01']], null), 422);
        self::assertSame(0, DB::table('report_export_jobs')->count());

        $job = $this->exports()->request($this->property(), $this->analystId, 'payments', ['preset' => 'today', 'ignored' => 'x', 'from' => '', 'nationality' => 'ID'], null);
        self::assertSame(['queued', 'payments', ['preset' => 'today']], [$job['status'], $job['report'], $job['params']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report.export_requested')->where('aggregate_id', $job['id'])->count());
    }

    public function test_the_runner_builds_the_export_as_the_requester_and_keeps_a_private_file_of_theirs(): void
    {
        $job = $this->exports()->request($this->property(), $this->analystId, 'payments', ['preset' => 'today'], null);
        $other = $this->exports()->request($this->property(), $this->analystId, 'flash', ['preset' => 'today'], null);
        self::assertSame(2, $this->exports()->runQueued($this->property()));

        $done = $this->exports()->overview($this->property(), $this->analystId)['jobs'];
        self::assertSame(['done', 'done'], array_column($done, 'status'));
        self::assertSame([true, true], array_column($done, 'ready'));
        self::assertNull($done[0]['file_id']);
        $row = DB::table('report_export_jobs')->where('id', $job['id'])->first();
        self::assertNotNull($row->file_id);
        self::assertStringStartsWith('payments-', $row->filename);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'reporting.export.finished')->where('aggregate_id', $job['id'])->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report.export_finished')->where('aggregate_id', $job['id'])->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'report.exported')->count());
        self::assertSame('2026-10-02', substr((string) DB::table('stored_files')->where('id', $row->file_id)->value('expires_at'), 0, 10));

        $download = $this->exports()->download($this->property(), $this->analystId, $job['id']);
        self::assertStringContainsString('Payment method', $download['file']->contents);
        $this->refused(fn () => $this->exports()->download($this->property(), $this->registrarId, $job['id']), 404);
        $this->refused(fn () => $this->exports()->download($this->property(), $this->analystId, str_repeat('0', 26)), 404);
        self::assertNotNull($other['id']);
    }

    public function test_the_person_is_told_until_they_look_and_only_about_their_own(): void
    {
        $this->exports()->request($this->property(), $this->analystId, 'payments', ['preset' => 'today'], null);
        self::assertSame(0, $this->exports()->unseen($this->property(), $this->analystId));
        $this->exports()->runQueued($this->property());

        self::assertSame([1, 0], [$this->exports()->unseen($this->property(), $this->analystId), $this->exports()->unseen($this->property(), $this->registrarId)]);
        self::assertSame(1, $this->exports()->overview($this->property(), $this->analystId)['unseen']);
        $this->exports()->markSeen($this->property(), $this->analystId);
        self::assertSame(0, $this->exports()->unseen($this->property(), $this->analystId));
        self::assertSame([], $this->exports()->overview($this->property(), $this->registrarId)['jobs']);
    }

    public function test_an_export_of_guests_is_built_with_its_purpose_and_audit(): void
    {
        $job = $this->exports()->request($this->property(), $this->registrarId, 'registrations', ['preset' => 'today'], 'Police request of 1 October');
        $this->exports()->runQueued($this->property());

        $download = $this->exports()->download($this->property(), $this->registrarId, $job['id']);
        self::assertStringContainsString('Budi Santoso', $download['file']->contents);
        self::assertStringContainsString('3174010101900001', $download['file']->contents);
        $audit = DB::table('audit_entries')->where('action', 'report.exported')->first();
        self::assertSame('Police request of 1 October', $audit->reason);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'export.issued')->count());
    }

    public function test_a_failing_export_says_why_and_notifies(): void
    {
        $job = $this->exports()->request($this->property(), $this->analystId, 'payments', ['from' => '2026-13-45', 'to' => '2026-13-46'], null);
        self::assertSame(1, $this->exports()->runQueued($this->property()));

        $failed = DB::table('report_export_jobs')->where('id', $job['id'])->first();
        self::assertSame(['failed', null], [$failed->status, $failed->file_id]);
        self::assertNotSame('', (string) $failed->error);
        self::assertSame(1, $this->exports()->unseen($this->property(), $this->analystId));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'reporting.export.failed')->where('aggregate_id', $job['id'])->count());
        $this->refused(fn () => $this->exports()->download($this->property(), $this->analystId, $job['id']), 409);
        self::assertSame(['failed'], array_column($this->exports()->overview($this->property(), $this->analystId)['jobs'], 'status'));
    }

    public function test_the_runner_takes_a_few_at_a_time_and_puts_back_what_a_stopped_worker_left(): void
    {
        $ids = [];

        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->exports()->request($this->property(), $this->analystId, 'payments', ['preset' => 'today'], null)['id'];
        }

        self::assertSame(5, $this->exports()->runQueued($this->property(), 5));
        self::assertSame(2, DB::table('report_export_jobs')->where('status', 'queued')->count());

        // A worker took one and stopped an hour ago; another is running now and left alone.
        DB::table('report_export_jobs')->where('id', $ids[5])->update(['status' => 'running', 'started_at' => '2026-10-01 01:00:00']);
        DB::table('report_export_jobs')->where('id', $ids[6])->update(['status' => 'running', 'started_at' => '2026-10-01 02:59:00']);
        self::assertSame(1, $this->exports()->runQueued($this->property()));
        self::assertSame(['done', 'running'], [DB::table('report_export_jobs')->where('id', $ids[5])->value('status'), DB::table('report_export_jobs')->where('id', $ids[6])->value('status')]);
    }

    public function test_the_file_expires_and_the_request_cannot_be_rewritten(): void
    {
        $job = $this->exports()->request($this->property(), $this->analystId, 'payments', ['preset' => 'today'], null);
        $this->exports()->runQueued($this->property());
        $this->clock->advance('+2 days');

        $this->refused(fn () => $this->exports()->download($this->property(), $this->analystId, $job['id']), 404);

        foreach (['report' => 'flash', 'requested_by' => $this->managerId] as $column => $value) {
            try {
                DB::table('report_export_jobs')->where('id', $job['id'])->update([$column => $value]);
                self::fail("{$column} must not change");
            } catch (QueryException $e) {
                self::assertStringContainsString('cannot be changed', $e->getMessage());
            }
        }

        try {
            DB::table('report_export_jobs')->where('id', $job['id'])->update(['status' => 'queued', 'file_id' => null]);
            self::fail('A finished export must not change');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }

        try {
            DB::table('report_export_jobs')->delete();
            self::fail('A request must not be deleted');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }

    public function test_the_scheduled_command_builds_the_waiting_exports_of_every_property(): void
    {
        $job = $this->exports()->request($this->property(), $this->analystId, 'payments', ['preset' => 'today'], null);
        app(PropertyContext::class)->clear();

        self::assertSame(0, Artisan::call('reports:run-exports'));
        self::assertStringContainsString('"built":1', Artisan::output());
        self::assertSame('done', DB::table('report_export_jobs')->where('id', $job['id'])->value('status'));
        self::assertNotNull(app(ExportJobRepository::class));
    }
}
