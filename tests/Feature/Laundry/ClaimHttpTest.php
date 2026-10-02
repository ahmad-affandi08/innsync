<?php

declare(strict_types=1);

namespace Tests\Feature\Laundry;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Laundry\Application\ClaimService;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ClaimHttpTest extends TestCase
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
            ClaimService::RECORD_PERMISSION,
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

    /** @return array<string, mixed> */
    private function bag(): array
    {
        $item = $this->postJson('/laundry/prices', ['code' => 'SHIRT', 'name' => 'Shirt', 'unit_price_minor' => 2_500_000, 'reason' => 'Opening list'])->assertCreated()->json('item');

        return $this->postJson('/laundry/orders', ['barcode' => 'BAG-77', 'room_id' => $this->roomId, 'express' => false, 'promised_date' => '2026-10-02', 'promised_time' => '17:00', 'lines' => [['price_item_id' => $item['id'], 'quantity' => 4]]], ['Idempotency-Key' => 'claim-http-order-01'])->assertCreated()->json('order');
    }

    public function test_a_claim_is_recorded_with_a_photo_and_decided_by_a_duty_manager_over_http(): void
    {
        config(['files.disk' => 'local']);
        $order = $this->bag();
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $line = $order['lines'][0]['id'];

        $this->post('/laundry/claims', ['order_id' => $order['id'], 'line_id' => $line, 'pieces' => 9, 'kind' => 'damage', 'description' => 'Scorch', 'claimed_minor' => 5_000_000], ['Accept' => 'application/json'])->assertStatus(422);
        $claim = $this->post('/laundry/claims', ['order_id' => $order['id'], 'line_id' => $line, 'pieces' => 2, 'kind' => 'damage', 'description' => 'Scorch', 'claimed_minor' => 5_000_000, 'photo' => UploadedFile::fake()->createWithContent('scorch.png', $png)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('claim.number', 'CLM-000001')->assertJsonPath('claim.status', 'open')->json('claim');
        $this->get("/laundry/claims/{$claim['id']}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/laundry/orders/{$order['id']}")->assertInertia(fn (Assert $p) => $p->has('claims', 1)->where('claims.0.number', 'CLM-000001'));
        $this->get('/laundry/claims')->assertInertia(fn (Assert $p) => $p->component('laundry/pages/claims')->has('overview.claims', 1)->where('overview.may.record', true)->where('overview.may.approve', false)->has('overview.orders', 1)->where('overview.claims.0.may_decide', false));
        $this->postJson("/laundry/claims/{$claim['id']}/approve", ['approved_minor' => 1_000_000, 'lock_version' => 0])->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ClaimService::APPROVE_PERMISSION, LaundryService::VIEW_PERMISSION]);
        $this->get('/laundry/claims?status=open')->assertInertia(fn (Assert $p) => $p->where('overview.claims.0.may_decide', true)->where('status', 'open')->has('overview.orders', 0));
        $this->postJson("/laundry/claims/{$claim['id']}/approve", ['approved_minor' => 99_000_000, 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/laundry/claims/{$claim['id']}/reject", ['note' => ' ', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/laundry/claims/{$claim['id']}/approve", ['approved_minor' => 3_000_000, 'note' => 'Agreed', 'lock_version' => 0])->assertOk()->assertJsonPath('claim.status', 'approved')->assertJsonPath('claim.approved_minor', 3_000_000);
        $this->postJson("/laundry/claims/{$claim['id']}/approve", ['approved_minor' => 3_000_000, 'lock_version' => 1])->assertStatus(409);
        $this->post('/laundry/claims', ['order_id' => $order['id'], 'pieces' => 1, 'kind' => 'loss', 'description' => 'x', 'claimed_minor' => 100], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_cap_is_set_by_the_price_list_manager_and_nobody_else_sees_claims(): void
    {
        $this->bag();
        $this->postJson('/laundry/claims/cap', ['cap_multiple' => 5, 'reason' => 'Policy'])->assertOk()->assertJsonPath('settings.cap_multiple', 5);
        $this->postJson('/laundry/claims/cap', ['cap_multiple' => 3, 'reason' => 'Again'])->assertStatus(409);
        $this->postJson('/laundry/claims/cap', ['cap_multiple' => 500, 'lock_version' => 0, 'reason' => 'Too big'])->assertStatus(422);

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);
        $this->get('/laundry/claims')->assertForbidden();
        $this->postJson('/laundry/claims/cap', ['cap_multiple' => 5, 'reason' => 'x'])->assertForbidden();
    }
}
