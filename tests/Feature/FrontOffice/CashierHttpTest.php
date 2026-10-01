<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
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

final class CashierHttpTest extends TestCase
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
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, CashierService::OPERATE_PERMISSION, CashierService::VIEW_PERMISSION, CashierService::MANAGE_PERMISSION, CashierService::SETTINGS_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'tentative',
        ], ['Idempotency-Key' => 'cashier-http-res-01'])->assertCreated()->json('reservation.id');
        $this->folio = $this->postJson("/front-office/reservations/{$reservation}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
    }

    public function test_a_shift_is_opened_used_dropped_and_closed_through_http(): void
    {
        $this->get('/front-office/cashier')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/cashier')->where('cashier.shift', null)->where('cashier.currency', 'IDR')->where('cashier.may_view_all', true));
        $shift = $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 20_000_000])->assertCreated()->assertJsonPath('shift.number', 'SHF-000001')->json('shift');
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 1])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'shift_open');

        $this->postJson("/front-office/folios/{$this->folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 5_000_000, 'purpose' => 'deposit'], ['Idempotency-Key' => 'cashier-pay-00000001'])->assertOk();
        $this->postJson("/front-office/folios/{$this->folio}/payments", ['payment_method' => 'qris', 'amount_minor' => 2_000_000, 'reference' => 'QR-1', 'purpose' => 'deposit'], ['Idempotency-Key' => 'cashier-pay-00000002'])->assertOk();

        $this->postJson("/front-office/cashier/shifts/{$shift['id']}/drops", ['amount_minor' => 30_000_000], ['Idempotency-Key' => 'cashier-drop-0000001'])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'drop_exceeds_cash');
        $this->postJson("/front-office/cashier/shifts/{$shift['id']}/drops", ['amount_minor' => 10_000_000, 'reference' => 'BAG-1'], ['Idempotency-Key' => 'cashier-drop-0000002'])->assertOk()->assertJsonPath('shift.drops_minor', 10_000_000);
        $this->postJson("/front-office/cashier/shifts/{$shift['id']}/drops", ['amount_minor' => 10_000_000, 'reference' => 'BAG-1'], ['Idempotency-Key' => 'cashier-drop-0000002'])->assertOk();
        self::assertSame(1, DB::table('cash_drops')->count());

        $this->get('/front-office/cashier')->assertInertia(fn (Assert $p) => $p->where('cashier.shift.number', 'SHF-000001')->where('cashier.shift.cash_on_hand_minor', 15_000_000)->has('cashier.shift.receipts', 2)->has('cashier.shift.drops', 1));

        $this->postJson("/front-office/cashier/shifts/{$shift['id']}/close", ['counted_cash_minor' => 14_000_000, 'lock_version' => 0])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['variance_reason']]]);
        $this->postJson("/front-office/cashier/shifts/{$shift['id']}/close", ['counted_cash_minor' => 14_000_000, 'variance_reason' => 'A coin tip given to the porter', 'lock_version' => 0])->assertOk()
            ->assertJsonPath('shift.status', 'closed')->assertJsonPath('shift.variance_minor', -1_000_000)->assertJsonPath('shift.expected_cash_minor', 15_000_000);

        $this->get("/front-office/cashier/shifts/{$shift['id']}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/cashier-shift')->where('detail.shift.status', 'closed')->where('detail.may_close', false)->where('detail.may_drop', false));
        $this->get('/front-office/cashier/shifts')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/cashier-shifts')->has('list.shifts', 1)->where('list.shifts.0.variance_minor', -1_000_000)->where('settings.require_open_shift', false));
        $this->get('/front-office/cashier/shifts?status=open')->assertInertia(fn (Assert $p) => $p->has('list.shifts', 0));
    }

    public function test_the_switch_requires_a_shift_before_money_is_taken(): void
    {
        $this->postJson('/front-office/cashier/settings', ['require_open_shift' => true, 'lock_version' => 0, 'reason' => 'Cash control policy'])->assertOk()->assertJsonPath('settings.require_open_shift', true);
        $this->postJson("/front-office/folios/{$this->folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 100, 'purpose' => 'deposit'], ['Idempotency-Key' => 'cashier-pay-00000010'])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'shift_required');
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 0])->assertCreated();
        $this->postJson("/front-office/folios/{$this->folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 100, 'purpose' => 'deposit'], ['Idempotency-Key' => 'cashier-pay-00000011'])->assertOk();
        $this->postJson('/front-office/cashier/settings', ['require_open_shift' => false, 'lock_version' => 0, 'reason' => 'Stale'])->assertStatus(409);
    }

    public function test_a_lapsed_password_confirmation_blocks_the_switch_and_strangers_see_nothing(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->postJson('/front-office/cashier/settings', ['require_open_shift' => true, 'lock_version' => 0, 'reason' => 'x'])->assertStatus(423);

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['housekeeping.task.view']);
        $this->get('/front-office/cashier')->assertForbidden();
        $this->get('/front-office/cashier/shifts')->assertForbidden();
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 0])->assertForbidden();
    }
}
