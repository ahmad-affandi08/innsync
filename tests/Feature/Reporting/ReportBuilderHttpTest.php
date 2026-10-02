<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\ReportBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ReportBuilderHttpTest extends TestCase
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

        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [ReportBuilderService::PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_the_builder_page_the_rows_and_the_csv_work_over_http(): void
    {
        $this->get('/reports/builder')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/builder')->has('catalogue.datasets', 4)->where('catalogue.currency', 'IDR')->where('catalogue.view_limit', 500)->where('catalogue.business_date', '2026-10-01'));

        $this->getJson('/reports/builder/run?dataset=reservations&columns[]=number&columns[]=status&filters[status]=confirmed&direction=desc')->assertOk()->assertJsonPath('report.columns', ['number', 'status'])->assertJsonPath('report.rows', [])->assertJsonPath('report.truncated', false);
        $this->getJson('/reports/builder/run?dataset=guests&columns[]=name')->assertStatus(422);
        $this->getJson('/reports/builder/run?dataset=reservations')->assertStatus(422);
        $this->getJson('/reports/builder/run?dataset=reservations&columns[]=guest_name')->assertStatus(422);
        $this->getJson('/reports/builder/run?dataset=reservations&columns[]=number&filters[status]=maybe')->assertStatus(422);

        $this->get('/reports/builder/export?dataset=postings&columns[]=code&columns[]=total_minor&from=2026-10-01&to=2026-10-31')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="builder-postings-2026-10-01-2026-10-31.csv"');
        $this->assertDatabaseHas('audit_entries', ['action' => 'report.exported']);
    }

    public function test_the_builder_right_is_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);

        $this->get('/reports/builder')->assertForbidden();
        $this->getJson('/reports/builder/run?dataset=reservations&columns[]=number')->assertForbidden();
        $this->get('/reports/builder/export?dataset=reservations&columns[]=number')->assertForbidden();
    }
}
