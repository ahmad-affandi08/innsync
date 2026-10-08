<?php

declare(strict_types=1);

namespace Tests\Feature\Laundry;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckOutLaundryException;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class LaundryHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $roomId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Clock::class, new AdjustableClock('2026-10-01 03:00:00'));
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION,
            ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, LaundryService::INTAKE_PERMISSION, LaundryService::PROCESS_PERMISSION,
            LaundryService::DELIVER_PERMISSION, LaundryService::CANCEL_PERMISSION, LaundryService::PRICES_PERMISSION, LaundryService::VIEW_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->roomId = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'laundry', 'effective_from' => '2026-10-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->get('/property/tax?scope=laundry')->assertInertia(fn (Assert $p) => $p->where('scope', 'laundry')->has('schemes', 1));
        $this->get('/property/tax?scope=bogus')->assertInertia(fn (Assert $p) => $p->where('scope', 'rooms'));
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'laundry-http-res-01'])->assertCreated()->json('reservation.id');
        $this->postJson("/front-office/reservations/{$reservation}/check-in", [
            'room_id' => $this->roomId, 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'laundry-http-ci-01'])->assertCreated();
    }

    public function test_the_price_list_intake_processing_charge_and_delivery_flow_over_http(): void
    {
        $this->get('/laundry/prices')->assertInertia(fn (Assert $p) => $p->component('laundry/pages/prices')->has('items', 0)->where('may.prices', true)->where('currency', 'IDR'));
        $item = $this->postJson('/laundry/prices', ['code' => 'SHIRT', 'name' => 'Shirt', 'unit_price_minor' => 2_500_000, 'reason' => 'Opening list'])->assertCreated()->json('item');
        $this->postJson('/laundry/prices', ['code' => 'SHIRT', 'name' => 'Again', 'unit_price_minor' => 1, 'reason' => 'x'])->assertStatus(422);
        $this->postJson("/laundry/prices/{$item['id']}", ['name' => 'Shirt', 'unit_price_minor' => 2_800_000, 'is_active' => true, 'lock_version' => 0, 'reason' => 'Review'])->assertOk()->assertJsonPath('item.unit_price_minor', 2_800_000);

        $this->get('/laundry/new')->assertInertia(fn (Assert $p) => $p->component('laundry/pages/intake')->where('lookups.rooms.0.number', '101')->where('lookups.items.0.code', 'SHIRT')->where('lookups.zone', 'Asia/Jakarta'));
        $body = ['barcode' => 'BAG-77', 'room_id' => $this->roomId, 'express' => true, 'promised_date' => '2026-10-02', 'promised_time' => '17:00', 'lines' => [['price_item_id' => $item['id'], 'quantity' => 3, 'brand' => 'Zara']]];
        $this->postJson('/laundry/orders', $body)->assertStatus(400);
        $order = $this->postJson('/laundry/orders', $body, ['Idempotency-Key' => 'laundry-http-order-1'])->assertCreated()->assertJsonPath('order.number', 'LDY-000001')->assertJsonPath('order.lines.0.unit_price_minor', 2_800_000)->json('order');
        $this->postJson('/laundry/orders', $body, ['Idempotency-Key' => 'laundry-http-order-1'])->assertCreated()->assertJsonPath('order.id', $order['id']);
        $this->postJson('/laundry/orders', [...$body, 'barcode' => 'BAG-77'], ['Idempotency-Key' => 'laundry-http-order-2'])->assertStatus(409);

        $this->get('/laundry')->assertInertia(fn (Assert $p) => $p->component('laundry/pages/queue')->has('orders', 1)->where('orders.0.express', true)->where('orders.0.status', 'sent'));
        $line = $order['lines'][0]['id'];
        $this->postJson("/laundry/orders/{$order['id']}/receive", ['counts' => [$line => 2], 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/laundry/orders/{$order['id']}/receive", ['counts' => [$line => 2], 'note' => 'One shirt missing', 'lock_version' => 0])->assertOk()->assertJsonPath('order.has_discrepancy', true);
        foreach ([1, 2, 3] as $version) {
            $this->postJson("/laundry/orders/{$order['id']}/advance", ['lock_version' => $version])->assertOk();
        }
        $this->postJson("/laundry/orders/{$order['id']}/ready", ['lock_version' => 3])->assertStatus(409);
        $this->postJson("/laundry/orders/{$order['id']}/ready", ['lock_version' => 4])->assertOk()->assertJsonPath('order.charged_minor', 5_600_000);
        self::assertSame(1, DB::table('folio_postings')->where('source', 'laundry')->count());

        $this->get("/laundry/orders/{$order['id']}")->assertInertia(fn (Assert $p) => $p->component('laundry/pages/order')->where('order.status', 'ready')->where('may.deliver', true));
        $this->postJson("/laundry/orders/{$order['id']}/deliver", ['receipt' => '', 'lock_version' => 5])->assertStatus(422);
        $this->postJson("/laundry/orders/{$order['id']}/deliver", ['receipt' => 'Mr Budi', 'lock_version' => 5])->assertOk()->assertJsonPath('order.status', 'delivered');
    }

    public function test_treatments_are_managed_and_chosen_at_hand_over_over_http(): void
    {
        $item = $this->postJson('/laundry/prices', ['code' => 'SHIRT', 'name' => 'Shirt', 'unit_price_minor' => 2_000_000, 'reason' => 'Opening list'])->assertCreated()->json('item.id');
        $dry = $this->postJson('/laundry/treatments', ['code' => 'DRY', 'name' => 'Dry cleaning', 'kind' => 'service', 'pricing' => 'percent', 'value' => 5_000, 'reason' => 'Price list'])->assertCreated()->assertJsonPath('treatment.value', 5_000)->json('treatment');
        $this->postJson('/laundry/treatments', ['code' => 'EXP', 'name' => 'Express', 'kind' => 'express', 'pricing' => 'fixed', 'value' => 500_000, 'reason' => 'Price list'])->assertCreated();
        $this->postJson('/laundry/treatments', ['code' => 'EXP2', 'name' => 'Express 2', 'kind' => 'express', 'pricing' => 'fixed', 'value' => 1, 'reason' => 'x'])->assertStatus(409);
        $this->postJson('/laundry/treatments', ['code' => 'BAD', 'name' => 'Bad', 'kind' => 'service', 'pricing' => 'percent', 'value' => 999_999, 'reason' => 'x'])->assertStatus(422);

        $this->get('/laundry/prices')->assertInertia(fn (Assert $p) => $p->has('treatments', 2));
        $this->get('/laundry/new')->assertInertia(fn (Assert $p) => $p->has('lookups.treatments', 2));
        $body = ['barcode' => 'BAG-88', 'room_id' => $this->roomId, 'express' => true, 'promised_date' => '2026-10-02', 'promised_time' => '17:00', 'lines' => [['price_item_id' => $item, 'quantity' => 2, 'treatment_id' => $dry['id']]]];
        $this->postJson('/laundry/orders', $body, ['Idempotency-Key' => 'laundry-http-treat-1'])->assertCreated()
            ->assertJsonPath('order.lines.0.treatment_name', 'Dry cleaning')->assertJsonPath('order.lines.0.treatment_extra_minor', 1_000_000)->assertJsonPath('order.lines.0.express_extra_minor', 500_000)
            ->assertJsonPath('order.billable_minor', 7_000_000);

        $this->postJson("/laundry/treatments/{$dry['id']}", ['name' => 'Dry cleaning', 'pricing' => 'percent', 'value' => 5_000, 'is_active' => false, 'lock_version' => 0, 'reason' => 'Stopped'])->assertOk()->assertJsonPath('treatment.is_active', false);
        $this->postJson("/laundry/treatments/{$dry['id']}", ['name' => 'Dry cleaning', 'pricing' => 'percent', 'value' => 5_000, 'is_active' => true, 'lock_version' => 0, 'reason' => 'Stale'])->assertStatus(409);
        $this->postJson('/laundry/orders', [...$body, 'barcode' => 'BAG-89'], ['Idempotency-Key' => 'laundry-http-treat-2'])->assertStatus(422);
    }

    public function test_someone_with_only_viewing_rights_sees_the_work_list_but_cannot_act(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [LaundryService::VIEW_PERMISSION]);

        $this->get('/laundry')->assertInertia(fn (Assert $p) => $p->component('laundry/pages/queue')->where('may.process', false));
        $this->get('/laundry/new')->assertForbidden();
        $this->postJson('/laundry/prices', ['code' => 'X1', 'name' => 'X', 'unit_price_minor' => 1, 'reason' => 'x'])->assertForbidden();
        $this->postJson('/laundry/orders/01arz3ndektsv4rrffq69g5fb1/advance', ['lock_version' => 0])->assertForbidden();
    }

    public function test_a_guest_who_leaves_with_laundry_in_hand_needs_an_approved_exception_and_the_stay_then_closes(): void
    {
        $item = $this->postJson('/laundry/prices', ['code' => 'SHIRT', 'name' => 'Shirt', 'unit_price_minor' => 2_500_000, 'reason' => 'Opening list'])->assertCreated()->json('item.id');
        $this->postJson('/laundry/orders', ['barcode' => 'BAG-1', 'room_id' => $this->roomId, 'express' => false, 'promised_date' => '2026-10-02', 'promised_time' => '17:00', 'lines' => [['price_item_id' => $item, 'quantity' => 2]]], ['Idempotency-Key' => 'laundry-http-exc-01'])->assertCreated();
        $stay = DB::table('stays')->first();
        $ask = fn (array $body, string $key) => $this->postJson("/front-office/stays/{$stay->id}/laundry-exception/approval", $body, ['Idempotency-Key' => $key]);

        // The stay cannot be closed while the laundry is in hand.
        $this->postJson("/front-office/stays/{$stay->id}/check-out", ['lock_version' => $stay->lock_version])->assertStatus(409);

        $ask(['mode' => 'nonsense', 'reason' => 'Guest left'], 'laundry-exc-ask-01')->assertStatus(422);
        $ask(['mode' => 'late_charge', 'reason' => ' '], 'laundry-exc-ask-02')->assertStatus(422);
        $ask(['mode' => 'late_charge', 'reason' => 'Guest left early'], 'laundry-exc-ask-03')->assertStatus(409);

        $admin = UserRecord::factory()->create();
        $this->grant($admin, self::A, [ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['laundry.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalPolicyAdmin::class)->define(PropertyId::fromString(self::A), strtolower((string) $admin->getKey()), CheckOutLaundryException::SUBJECT, 0, [['permission' => 'laundry.test.approve']], 'Owner policy');

        $approval = (string) $ask(['mode' => 'late_charge', 'reason' => 'Guest left early'], 'laundry-exc-ask-04')->assertCreated()->assertJsonPath('approval.status', 'pending')->json('approval.id');
        $exception = ['mode' => 'late_charge', 'reason' => 'Guest left early', 'approval_id' => $approval];
        $this->postJson("/front-office/stays/{$stay->id}/check-out", ['lock_version' => $stay->lock_version, 'laundry_exception' => $exception])->assertStatus(409);
        self::assertSame(0, DB::table('stay_laundry_exceptions')->count());

        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $approval, strtolower((string) $approver->getKey()));

        // The approval is for this reason and these orders: another reason is not covered by it.
        $this->postJson("/front-office/stays/{$stay->id}/check-out", ['lock_version' => $stay->lock_version, 'laundry_exception' => [...$exception, 'reason' => 'Something else']])->assertStatus(409);
        $this->postJson("/front-office/stays/{$stay->id}/check-out", ['lock_version' => $stay->lock_version, 'laundry_exception' => $exception])->assertOk();

        self::assertSame(1, DB::table('stay_laundry_exceptions')->where('mode', 'late_charge')->count());
        self::assertSame('checked_out', DB::table('stays')->where('id', $stay->id)->value('status'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'stay.laundry_exception')->count());
    }
}
