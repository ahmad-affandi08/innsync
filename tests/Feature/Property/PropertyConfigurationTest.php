<?php

declare(strict_types=1);

namespace Tests\Feature\Property;

use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\BusinessDateNotSet;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class PropertyConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

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
        $this->createProperty(self::B, 'B');
    }

    /** @return array<string, mixed> */
    private function type(array $override = []): array
    {
        return ['code' => 'dlx', 'name' => 'Deluxe', 'description' => 'City view', 'max_adults' => 2, 'max_children' => 1, 'sort_order' => 10, 'reason' => 'Initial setup', ...$override];
    }

    private function manager(): void
    {
        $this->signIn(self::A, [RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
    }

    public function test_a_manager_creates_a_room_type_and_a_room_and_everything_is_audited(): void
    {
        $this->manager();

        $type = $this->postJson('/property/room-types', $this->type())->assertCreated()->json('type');
        self::assertSame('DLX', $type['code']);

        $room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type['id'], 'floor' => '1', 'reason' => 'Initial setup'])->assertCreated()->json('room');
        self::assertSame('101', $room['number']);

        self::assertSame(['room_type.created', 'room.created'], DB::table('audit_entries')->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());

        $this->get('/property/rooms')->assertInertia(fn (Assert $page) => $page
            ->component('property/pages/room-catalog')->has('types', 1)->has('rooms', 1));
    }

    public function test_people_without_the_permission_get_the_standard_forbidden_error_and_nothing_changes(): void
    {
        $this->signIn(self::A, ['front-office.reservation.view']);

        $this->postJson('/property/room-types', $this->type())->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->putJson('/property/settings', ['check_in_time' => '14:00'])->assertStatus(422);
        self::assertSame(0, DB::table('room_types')->count());
    }

    public function test_a_viewer_can_read_but_not_change_the_catalogue(): void
    {
        $this->signIn(self::A, [RoomCatalogService::VIEW_PERMISSION]);

        $this->get('/property/rooms')->assertOk();
        $this->postJson('/property/room-types', $this->type())->assertForbidden();
    }

    public function test_duplicates_bad_codes_and_missing_reasons_are_refused_with_field_errors(): void
    {
        $this->manager();
        $this->postJson('/property/room-types', $this->type())->assertCreated();

        $this->postJson('/property/room-types', $this->type(['code' => 'DLX']))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->postJson('/property/room-types', $this->type(['code' => '1X!']))->assertStatus(422);
        $this->postJson('/property/room-types', $this->type(['code' => 'STD', 'reason' => '']))->assertStatus(422);
        $this->postJson('/property/room-types', $this->type(['code' => 'STD', 'max_adults' => 0]))->assertStatus(422);
        self::assertSame(1, DB::table('room_types')->count());
    }

    public function test_a_room_number_is_unique_per_property_but_may_exist_in_another_property(): void
    {
        $this->manager();
        $type = $this->postJson('/property/room-types', $this->type())->json('type');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type['id'], 'reason' => 'x'])->assertCreated();

        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type['id'], 'reason' => 'x'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['number']]]);
        $this->postJson('/property/rooms', ['number' => '12a', 'room_type_id' => $type['id'], 'reason' => 'x'])->assertCreated()->assertJsonPath('room.number', '12A');

        self::assertSame(2, DB::table('rooms')->count());
    }

    public function test_a_room_cannot_use_another_propertys_type_or_an_inactive_type(): void
    {
        $this->manager();
        $type = $this->postJson('/property/room-types', $this->type())->json('type');
        DB::table('room_types')->insert([
            'id' => '01arz3ndektsv4rrffq69g5fax', 'property_id' => self::B, 'code' => 'OTH', 'name' => 'Other', 'max_adults' => 2, 'max_children' => 0,
            'sort_order' => 0, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/property/rooms', ['number' => '201', 'room_type_id' => '01arz3ndektsv4rrffq69g5fax', 'reason' => 'x'])->assertStatus(422);

        $this->postJson("/property/room-types/{$type['id']}/active", ['active' => false, 'lock_version' => 0, 'reason' => 'Retire'])->assertOk();
        $this->postJson('/property/rooms', ['number' => '201', 'room_type_id' => $type['id'], 'reason' => 'x'])->assertStatus(422);
    }

    public function test_a_type_with_active_rooms_cannot_be_deactivated_and_inactive_rooms_cannot_be_reactivated_under_an_inactive_type(): void
    {
        $this->manager();
        $type = $this->postJson('/property/room-types', $this->type())->json('type');
        $room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type['id'], 'reason' => 'x'])->json('room');

        $this->postJson("/property/room-types/{$type['id']}/active", ['active' => false, 'lock_version' => 0, 'reason' => 'Retire'])->assertStatus(409);

        $room = $this->postJson("/property/rooms/{$room['id']}/active", ['active' => false, 'lock_version' => 0, 'reason' => 'Closed for renovation'])->assertOk()->json('room');
        $type = $this->postJson("/property/room-types/{$type['id']}/active", ['active' => false, 'lock_version' => 0, 'reason' => 'Retire'])->assertOk()->json('type');
        self::assertFalse($type['is_active']);

        $this->postJson("/property/rooms/{$room['id']}/active", ['active' => true, 'lock_version' => $room['lock_version'], 'reason' => 'Reopen'])->assertStatus(409);
    }

    public function test_a_stale_edit_is_a_conflict_and_never_overwrites(): void
    {
        $this->manager();
        $type = $this->postJson('/property/room-types', $this->type())->json('type');

        $edit = ['name' => 'Deluxe Plus', 'max_adults' => 3, 'max_children' => 1, 'lock_version' => 0, 'reason' => 'Rename'];
        $this->putJson("/property/room-types/{$type['id']}", $edit)->assertOk()->assertJsonPath('type.lock_version', 1);
        $this->putJson("/property/room-types/{$type['id']}", [...$edit, 'name' => 'Stale'])->assertStatus(409)->assertJsonPath('error.conflict.action', 'refresh');

        self::assertSame('Deluxe Plus', DB::table('room_types')->value('name'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'room_type.updated')->count());
    }

    public function test_the_catalogue_is_scoped_to_the_active_property(): void
    {
        $this->manager();
        DB::table('room_types')->insert([
            'id' => '01arz3ndektsv4rrffq69g5fax', 'property_id' => self::B, 'code' => 'OTH', 'name' => 'Other', 'max_adults' => 2, 'max_children' => 0,
            'sort_order' => 0, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/property/rooms')->assertInertia(fn (Assert $page) => $page->has('types', 0));
        $this->putJson('/property/room-types/01arz3ndektsv4rrffq69g5fax', ['name' => 'Hack', 'max_adults' => 2, 'max_children' => 0, 'lock_version' => 0, 'reason' => 'x'])->assertNotFound();
        self::assertSame('Other', DB::table('room_types')->value('name'));
    }

    public function test_the_database_refuses_to_delete_types_and_rooms(): void
    {
        $this->manager();
        $type = $this->postJson('/property/room-types', $this->type())->json('type');
        $room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type['id'], 'reason' => 'x'])->json('room');

        foreach ([fn () => DB::table('rooms')->where('id', $room['id'])->delete(), fn () => DB::table('room_types')->where('id', $type['id'])->delete()] as $delete) {
            try {
                $delete();
                self::fail('A catalogue row was deleted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_settings_default_to_the_indonesian_baseline_and_can_be_changed_with_a_reason(): void
    {
        $this->manager();

        $this->get('/property/settings')->assertInertia(fn (Assert $page) => $page
            ->where('settings.check_in_time', '14:00')->where('settings.check_out_time', '12:00')->where('settings.night_audit_earliest_time', '23:00')
            ->where('settings.rounding_increment_minor', 100)->where('settings.rounding_mode', 'half_up')->where('settings.availability_horizon_days', 365)->where('settings.business_date', null));

        $this->putJson('/property/settings', ['check_in_time' => '15:00', 'check_out_time' => '11:00', 'night_audit_earliest_time' => '22:30', 'rounding_increment_minor' => 100, 'rounding_mode' => 'half_up', 'availability_horizon_days' => 400, 'lock_version' => 0, 'reason' => 'Group policy'])
            ->assertOk()->assertJsonPath('settings.check_in_time', '15:00')->assertJsonPath('settings.lock_version', 1);

        self::assertSame(1, DB::table('audit_entries')->where('action', 'property.settings.changed')->count());
    }

    public function test_invalid_times_modes_and_stale_versions_are_refused(): void
    {
        $this->manager();
        $ok = ['check_in_time' => '14:00', 'check_out_time' => '12:00', 'night_audit_earliest_time' => '23:00', 'rounding_increment_minor' => 100, 'rounding_mode' => 'half_up', 'availability_horizon_days' => 365, 'lock_version' => 0, 'reason' => 'x'];

        $this->putJson('/property/settings', [...$ok, 'check_in_time' => '25:00'])->assertStatus(422);
        $this->putJson('/property/settings', [...$ok, 'rounding_mode' => 'banana'])->assertStatus(422);
        $this->putJson('/property/settings', [...$ok, 'availability_horizon_days' => 10])->assertStatus(422);
        $this->putJson('/property/settings', [...$ok, 'lock_version' => 5])->assertStatus(409);
        self::assertSame(0, DB::table('property_settings')->count());
    }

    public function test_the_business_date_is_set_once_at_go_live_and_only_moves_forward_afterwards(): void
    {
        $this->manager();
        app(PropertyContext::class)->activateFromString(self::A);
        $provider = app(BusinessDateProvider::class);

        try {
            $provider->current(PropertyId::fromString(self::A));
            self::fail('A business date was invented before go-live.');
        } catch (BusinessDateNotSet) {
            $this->addToAssertionCount(1);
        }

        $this->postJson('/property/settings/business-date', ['business_date' => '2026-02-30', 'lock_version' => 0, 'reason' => 'Go-live'])->assertStatus(422);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk()->assertJsonPath('settings.business_date', '2026-10-01');
        app(PropertyContext::class)->activateFromString(self::A);
        self::assertSame('2026-10-01', $provider->current(PropertyId::fromString(self::A))->toString());

        // Setting it again is refused: only night audit advances it.
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 1, 'reason' => 'Skip ahead'])->assertStatus(409);

        foreach ([['2026-09-30'], [null]] as [$date]) {
            try {
                DB::table('property_settings')->update(['business_date' => $date]);
                self::fail('The business date moved backwards or was cleared.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        DB::table('property_settings')->update(['business_date' => '2026-10-02']);
        app(PropertyContext::class)->activateFromString(self::A);
        self::assertSame('2026-10-02', $provider->current(PropertyId::fromString(self::A))->toString());
    }

    public function test_settings_changes_need_a_recent_password_confirmation(): void
    {
        $this->manager();
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);

        $this->putJson('/property/settings', ['check_in_time' => '14:00'])->assertStatus(423);
    }

    public function test_the_reconfirm_route_accepts_only_same_site_return_paths(): void
    {
        $this->manager();

        $this->get('/reconfirm?return=/property/rooms')->assertRedirect('/property/rooms');
        foreach (['https://evil.example/x', '//evil.example', '/../etc', 'javascript:alert(1)', '/a\\b'] as $bad) {
            $this->get('/reconfirm?return='.urlencode($bad))->assertRedirect('/');
        }

        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->get('/reconfirm?return=/property/rooms')->assertRedirect(route('password.confirm'));
    }
}
