<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class CompanyHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservationId;

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
        $this->signIn(self::A, [CompanyService::MANAGE_PERMISSION, CompanyService::LINK_PERMISSION, ReservationService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservationId = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'tentative',
        ], ['Idempotency-Key' => 'company-http-key-0001'])->assertCreated()->json('reservation.id');
    }

    public function test_profiles_and_billing_a_reservation_to_a_company_work_over_http(): void
    {
        $company = $this->postJson('/front-office/companies', ['code' => 'ACME', 'name' => 'PT Acme', 'kind' => 'company', 'credit_limit_minor' => 500_000_000, 'billing_instruction' => 'Invoice to finance', 'route_rooms' => true, 'route_extras' => true])->assertCreated()->assertJsonPath('company.code', 'ACME')->json('company');
        $this->postJson('/front-office/companies', ['code' => 'ACME', 'name' => 'Again', 'kind' => 'company'])->assertStatus(422);
        $this->postJson('/front-office/companies', ['code' => 'X', 'name' => 'Short code', 'kind' => 'company'])->assertStatus(422);

        $this->get('/front-office/companies')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/companies')->has('overview.companies', 1)->where('overview.may.manage', true)->where('accounts.total_minor', 0)->where('currency', 'IDR'));

        $this->postJson("/front-office/companies/{$company['id']}", ['name' => 'PT Acme', 'kind' => 'company', 'is_active' => true, 'lock_version' => 0, 'reason' => 'New limit', 'credit_limit_minor' => 700_000_000])->assertOk()->assertJsonPath('company.credit_limit_minor', 700_000_000)->assertJsonPath('company.lock_version', 1);
        $this->postJson("/front-office/companies/{$company['id']}", ['name' => 'PT Acme', 'kind' => 'company', 'is_active' => true, 'lock_version' => 0, 'reason' => 'Stale'])->assertStatus(409);

        $this->get("/front-office/reservations/{$this->reservationId}")->assertInertia(fn (Assert $p) => $p->where('billing.may_link', true)->has('billing.options', 1)->where('billing.company', null));

        // A travel agent, which is what an online travel agency is, comes first in the list however its name sorts.
        $this->postJson('/front-office/companies', ['code' => 'TRVL', 'name' => 'Zeta Travel', 'kind' => 'agent', 'route_rooms' => true, 'route_extras' => false])->assertCreated();
        $this->get("/front-office/reservations/{$this->reservationId}")->assertInertia(fn (Assert $p) => $p->has('billing.options', 2)->where('billing.options.0.code', 'TRVL')->where('billing.options.0.kind', 'agent')->where('billing.options.1.code', 'ACME'));
        $this->postJson("/front-office/reservations/{$this->reservationId}/company", ['company_id' => $company['id']])->assertOk()->assertJsonPath('billing.company.code', 'ACME');
        $this->postJson("/front-office/reservations/{$this->reservationId}/company", ['company_id' => $company['id']])->assertStatus(409);
        $this->get("/front-office/reservations/{$this->reservationId}")->assertInertia(fn (Assert $p) => $p->where('billing.company.code', 'ACME')->where('billing.may_link', false)->where('billing.folio_id', fn ($id) => is_string($id)));
        self::assertSame(1, DB::table('company_folios')->where('reservation_id', $this->reservationId)->count());
    }

    public function test_the_company_rights_are_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION]);

        $this->get('/front-office/companies')->assertForbidden();
        $this->postJson('/front-office/companies', ['code' => 'ACME', 'name' => 'PT Acme', 'kind' => 'company'])->assertForbidden();
        $this->postJson("/front-office/reservations/{$this->reservationId}/company", ['company_id' => str_repeat('0', 26)])->assertForbidden();
        $this->get("/front-office/reservations/{$this->reservationId}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('billing.company', null)->where('billing.may_link', false));
    }
}
