<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/**
 * NFR-14 and NFR-15: the version of the application is on every page and the guide by role is inside the product. And a regression the real browser found: on a hotel with no payables or receivables yet the
 * finance pages had no currency to format zero in, and crashed.
 */
final class GuideAndEmptyStateTest extends TestCase
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
        $this->signIn(self::A, [FinanceAccess::PAYABLE_VIEW, FinanceAccess::RECEIVABLE_VIEW, PropertySettingsService::MANAGE_PERMISSION]);
    }

    public function test_the_guide_is_a_page_for_everyone_signed_in_and_every_page_carries_the_version(): void
    {
        config(['app.version' => '1.2.3-test']);

        $this->get('/help')->assertOk()->assertInertia(fn (Assert $p) => $p->component('foundation/pages/guide')->where('app.version', '1.2.3-test'));
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('app.version', '1.2.3-test'));

        $this->post('/logout');
        $this->get('/help')->assertRedirect();
    }

    public function test_a_hotel_that_has_not_gone_live_is_told_so_instead_of_getting_a_server_error(): void
    {
        $this->get('/finance/payables')->assertStatus(409);
        $this->getJson('/finance/payables')->assertStatus(409)->assertJsonPath('error.conflict.reason', 'business_date_not_set');
    }

    public function test_the_finance_pages_know_their_currency_before_the_first_document(): void
    {
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->get('/finance/payables')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.currency', 'IDR')->has('overview.payables', 0));
        $this->get('/finance/aging')->assertOk()->assertInertia(fn (Assert $p) => $p->where('report.currency', 'IDR'));
        $this->get('/finance/receivables')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.currency', 'IDR'));
        $this->get('/finance/receivables/aging')->assertOk()->assertInertia(fn (Assert $p) => $p->where('aging.currency', 'IDR'));
    }
}
