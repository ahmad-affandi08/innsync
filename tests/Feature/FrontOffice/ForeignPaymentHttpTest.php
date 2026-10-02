<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\ForeignPayments\ForeignPaymentService;
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

final class ForeignPaymentHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $folio;

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
        $this->signIn(self::A, [ForeignPaymentService::SETTINGS_PERMISSION, ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'tentative',
        ], ['Idempotency-Key' => 'fx-http-key-000001'])->assertCreated()->json('reservation.id');
        $this->folio = $this->postJson("/front-office/reservations/{$reservation}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
        $this->postJson("/front-office/folios/{$this->folio}/charges", ['code' => 'MINIBAR', 'description' => 'Minibar', 'amount_minor' => 100_000_000, 'prices_include_charges' => false], ['Idempotency-Key' => 'fx-http-charge-001'])->assertOk();
    }

    public function test_the_switch_the_rates_and_a_foreign_payment_work_over_http(): void
    {
        $this->get("/front-office/folios/{$this->folio}")->assertInertia(fn (Assert $p) => $p->where('foreign.enabled', false)->where('foreign.rates', []));
        $this->postJson("/front-office/folios/{$this->folio}/foreign-payments", ['payment_method' => 'cash', 'currency' => 'USD', 'foreign_minor' => 5000, 'purpose' => 'settlement'], ['Idempotency-Key' => 'fx-http-pay-0001'])->assertStatus(409);

        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->postJson('/front-office/foreign-currency/enabled', ['enabled' => true, 'lock_version' => 0, 'reason' => 'Counsel agreed'])->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/front-office/foreign-currency/enabled', ['enabled' => true, 'lock_version' => 0, 'reason' => 'Counsel agreed'])->assertOk()->assertJsonPath('settings.enabled', true);
        $this->postJson('/front-office/foreign-currency/rates', ['currency' => 'USD', 'rate_e4' => 160_500_000, 'reason' => 'Bank counter'])->assertCreated()->assertJsonPath('rate.version', 1);
        $this->postJson('/front-office/foreign-currency/rates', ['currency' => 'JPY', 'rate_e4' => 1_000_000, 'reason' => 'Bank counter'])->assertStatus(422);

        $this->get("/front-office/folios/{$this->folio}")->assertInertia(fn (Assert $p) => $p->where('foreign.enabled', true)->where('foreign.home_currency', 'IDR')->has('foreign.rates', 1)->where('foreign.rates.0.currency', 'USD'));

        $paid = $this->postJson("/front-office/folios/{$this->folio}/foreign-payments", ['payment_method' => 'cash', 'currency' => 'USD', 'foreign_minor' => 5000, 'purpose' => 'settlement'], ['Idempotency-Key' => 'fx-http-pay-0001'])->assertOk()->assertJsonPath('foreign.booked_minor', 80_250_000)->assertJsonPath('posting.total_minor', -80_250_000)->json();
        $again = $this->postJson("/front-office/folios/{$this->folio}/foreign-payments", ['payment_method' => 'cash', 'currency' => 'USD', 'foreign_minor' => 5000, 'purpose' => 'settlement'], ['Idempotency-Key' => 'fx-http-pay-0001'])->assertOk()->json();
        self::assertSame($paid['posting']['id'], $again['posting']['id']);
        self::assertSame(1, DB::table('foreign_payments')->count());

        $this->get('/front-office/foreign-currency')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/foreign-currency')->where('overview.enabled', true)->has('overview.recent', 1)->where('overview.today.0.currency', 'USD')->where('overview.today.0.booked_minor', 80_250_000));
    }

    public function test_the_foreign_currency_rights_are_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [FolioService::MANAGE_PERMISSION]);

        $this->get('/front-office/foreign-currency')->assertForbidden();
        $this->postJson('/front-office/foreign-currency/rates', ['currency' => 'USD', 'rate_e4' => 160_500_000, 'reason' => 'x'])->assertForbidden();
    }
}
