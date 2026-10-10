<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** PRD 4.2, FR-FO-003: the bookings of a channel come in from its file, one way; the same booking is one reservation however often it is sent. */
final class ReservationImportHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const HEADER = "channel,external_ref,status,guest_name,phone,email,arrival,departure,adults,children,room_type_code,rate_plan_code,channel_total,notes\n";

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['identity_access.login_rate_limit_per_minute' => 1000]);
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $type = $this->postJson('/property/room-types', ['code' => 'STD', 'name' => 'Standard', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/rooms', ['number' => '102', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'OTA', 'name' => 'OTA rate', 'kind' => 'ota', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function upload(string $rows, bool $dryRun)
    {
        return $this->post('/front-office/reservations/import', ['file' => UploadedFile::fake()->createWithContent('bookings.csv', self::HEADER.$rows), 'dry_run' => $dryRun ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_a_file_is_checked_without_a_trace_then_imported_and_a_second_time_changes_nothing(): void
    {
        $rows = "booking_com,BK-1001,confirmed,Budi Santoso,0812 3456,budi@example.com,2026-11-10,2026-11-12,2,0,STD,OTA,1450000,Late arrival\n"
            ."booking_com,BK-1002,tentative,Sari Wulandari,,sari@example.com,2026-11-10,2026-11-11,1,0,std,ota,,\n";

        $check = $this->upload($rows, true)->assertOk()->assertJsonPath('status', 'validated')->assertJsonPath('count', 2)->assertJsonPath('summary.created', 2);
        self::assertSame(0, DB::table('reservations')->count(), 'a check leaves nothing');
        self::assertSame(0, DB::table('reservation_external_refs')->count());
        self::assertNotNull($check->json('summary'));

        $this->upload($rows, false)->assertOk()->assertJsonPath('status', 'applied')->assertJsonPath('summary.created', 2)->assertJsonPath('summary.skipped', 0);
        $made = DB::table('reservations')->orderBy('number')->get();
        self::assertCount(2, $made);
        self::assertSame(['ota', 'ota'], $made->pluck('source')->all());
        self::assertSame(['confirmed', 'tentative'], $made->pluck('status')->all());
        self::assertStringContainsString('[booking_com BK-1001] channel total 1450000 Late arrival', (string) $made[0]->notes);
        self::assertSame(2, DB::table('reservation_external_refs')->count());

        // The same file again, and the same booking in another file, make nothing.
        $this->upload($rows, false)->assertOk()->assertJsonPath('status', 'applied')->assertJsonPath('summary.created', 0)->assertJsonPath('summary.skipped', 2);
        self::assertSame(2, DB::table('reservations')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'reservation.created')->where('aggregate_id', $made[0]->id)->count());
    }

    public function test_a_cancelled_row_cancels_the_booking_that_was_imported_and_one_that_never_was_is_left(): void
    {
        $this->upload("booking_com,BK-2001,confirmed,Budi,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\n", false)->assertOk()->assertJsonPath('summary.created', 1);

        $cancel = "booking_com,BK-2001,cancelled,Budi,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\nbooking_com,BK-9999,cancelled,Nobody,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\n";
        $this->upload($cancel, false)->assertOk()->assertJsonPath('status', 'applied')->assertJsonPath('summary.cancelled', 1)->assertJsonPath('summary.skipped', 1);
        self::assertSame('cancelled', DB::table('reservations')->value('status'));
        self::assertSame(1, DB::table('reservations')->count(), 'a cancellation never makes a booking');

        // A cancellation sent again changes nothing.
        $this->upload($cancel, false)->assertOk()->assertJsonPath('summary.cancelled', 0)->assertJsonPath('summary.skipped', 2);
    }

    public function test_a_file_with_a_bad_row_imports_nothing_and_names_every_bad_line(): void
    {
        $rows = "booking_com,BK-3001,confirmed,Budi,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\n"
            ."booking_com,BK-3002,confirmed,Sari,0812 3456,,2026-11-10,2026-11-12,2,0,SUITE,OTA,,\n"
            ."booking com,BK-3003,confirmed,Rina,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\n"
            ."booking_com,BK-3004,confirmed,Dewi,0812 3456,,2026-11-12,2026-11-10,2,0,STD,OTA,,\n"
            ."booking_com,BK-3005,maybe,Agus,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\n"
            ."booking_com,BK-3006,confirmed,Tono,0812 3456,,2026-11-10,2026-11-12,9,0,STD,OTA,,\n"
            ."booking_com,BK-3007,confirmed,Wati,0812 3456,,2026-11-10,2026-11-12,2,0,STD,GHOST,,\n"
            ."booking_com,BK-3008,confirmed,Yanti,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,about 1.4m,\n";

        $report = $this->upload($rows, false)->assertOk()->assertJsonPath('status', 'rejected')->json();
        self::assertSame([3, 4, 5, 6, 7, 8, 9], array_column($report['errors'], 'line'));
        self::assertSame(0, DB::table('reservations')->count());
        self::assertSame(0, DB::table('reservation_external_refs')->count());
    }

    public function test_a_booking_the_hotel_cannot_honour_is_refused_with_its_line_and_the_whole_file_waits(): void
    {
        $day = fn (string $ref, string $name) => "booking_com,{$ref},confirmed,{$name},0812 3456,,2026-12-01,2026-12-03,2,0,STD,OTA,,\n";
        // Two rooms of that type: the third booking for the same nights has nowhere to go.
        $report = $this->upload($day('BK-4001', 'A').$day('BK-4002', 'B').$day('BK-4003', 'C'), false)->assertOk()->assertJsonPath('status', 'rejected')->json();

        self::assertSame([4], array_column($report['errors'], 'line'));
        self::assertSame(0, DB::table('reservations')->count());
    }

    public function test_it_needs_the_right_to_manage_reservations_and_the_file_must_have_its_columns(): void
    {
        $this->get('/front-office/reservations/import/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->post('/front-office/reservations/import', ['file' => UploadedFile::fake()->createWithContent('b.csv', "channel,guest_name\nx,y\n"), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'rejected');

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);
        $this->upload("booking_com,BK-5001,confirmed,Budi,0812 3456,,2026-11-10,2026-11-12,2,0,STD,OTA,,\n", false)->assertForbidden();
        self::assertSame(0, DB::table('reservations')->count());
    }
}
