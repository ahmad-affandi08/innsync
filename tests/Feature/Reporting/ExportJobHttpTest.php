<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ExportJobHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['files.disk' => 'local']);
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [ReportService::VIEW_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_an_export_is_asked_for_built_by_the_runner_and_downloaded_over_http(): void
    {
        $this->get('/reports/exports')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/exports')->has('overview.jobs', 0)->where('overview.unseen', 0)->has('overview.reports'));
        $this->postJson('/reports/exports', ['report' => 'nonsense', 'params' => []])->assertStatus(422);
        $this->postJson('/reports/exports', ['report' => 'registrations', 'params' => [], 'purpose' => 'Audit'])->assertForbidden();
        $this->postJson('/reports/exports', ['report' => 'payments', 'params' => ['from' => 'bad']])->assertStatus(422);
        $job = $this->postJson('/reports/exports', ['report' => 'payments', 'params' => ['preset' => 'today']])->assertCreated()->assertJsonPath('job.status', 'queued')->json('job');
        $this->get("/reports/exports/{$job['id']}/download")->assertStatus(409);

        self::assertSame(0, Artisan::call('reports:run-exports'));
        $this->get('/reports')->assertInertia(fn (Assert $p) => $p->where('exports_unseen', 1));
        $this->get('/reports/exports')->assertInertia(fn (Assert $p) => $p->where('overview.jobs.0.status', 'done')->where('overview.jobs.0.ready', true));
        $this->get("/reports/exports/{$job['id']}/download")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Content-Disposition', 'attachment; filename="'.DB::table('report_export_jobs')->where('id', $job['id'])->value('filename').'"');
        $this->postJson('/reports/exports/seen')->assertOk();
        $this->get('/reports')->assertInertia(fn (Assert $p) => $p->where('exports_unseen', 0));

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReportService::VIEW_PERMISSION]);
        $this->get("/reports/exports/{$job['id']}/download")->assertNotFound();
        $this->get('/reports/exports')->assertInertia(fn (Assert $p) => $p->has('overview.jobs', 0));
    }

    public function test_someone_with_no_report_right_cannot_ask_for_an_export(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);

        $this->postJson('/reports/exports', ['report' => 'payments', 'params' => ['preset' => 'today']])->assertForbidden();
        $this->get('/reports/exports')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.reports', []));
    }
}
