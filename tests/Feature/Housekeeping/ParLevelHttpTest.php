<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\LinenService;
use App\Modules\Housekeeping\Application\ParLevelService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ParLevelHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $type;

    private string $item;

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
        $this->signIn(self::A, [ParLevelService::MANAGE_PERMISSION, LinenService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->item = $this->postJson('/housekeeping/linen/items', ['code' => 'SHEET_Q', 'name' => 'Queen sheet', 'kind' => 'linen', 'unit' => 'pcs'])->assertCreated()->json('item.id');
    }

    public function test_par_levels_are_set_listed_and_read_by_shift_over_http(): void
    {
        $this->get('/housekeeping/par-levels')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/par-levels')->has('overview.items', 1)->has('overview.shifts', 3)->has('overview.levels', 0)->where('overview.may.manage', true)->where('overview.room_types.0.rooms', 1));

        $level = $this->postJson('/housekeeping/par-levels', ['item_id' => $this->item, 'scope_kind' => 'room_type', 'scope_ref' => $this->type, 'par_quantity' => 4, 'use_quantity' => 2, 'reason' => 'Standard'])->assertOk()->assertJsonPath('level.par_quantity', 4)->json('level');
        $this->postJson('/housekeeping/par-levels', ['item_id' => $this->item, 'scope_kind' => 'room_type', 'scope_ref' => $this->type, 'par_quantity' => 5, 'use_quantity' => 2, 'reason' => 'Again'])->assertStatus(409);
        $this->postJson('/housekeeping/par-levels', ['item_id' => $this->item, 'scope_kind' => 'room_type', 'scope_ref' => $this->type, 'par_quantity' => 5, 'use_quantity' => 2, 'lock_version' => $level['lock_version'], 'reason' => 'More'])->assertOk()->assertJsonPath('level.par_quantity', 5);
        $this->postJson('/housekeeping/par-levels', ['item_id' => $this->item, 'scope_kind' => 'area', 'scope_ref' => 'Lobby', 'par_quantity' => 6, 'use_quantity' => 1, 'reason' => 'x'])->assertStatus(422);
        $this->postJson('/housekeeping/par-levels', ['item_id' => $this->item, 'scope_kind' => 'area', 'scope_ref' => 'Lobby', 'par_quantity' => 6, 'use_quantity' => 0, 'reason' => 'Lobby linen'])->assertOk();

        $this->get('/housekeeping/par-levels')->assertInertia(fn (Assert $p) => $p->has('overview.levels', 2)->where('overview.replenishment.0.target', 11)->where('overview.replenishment.0.need', 11)->where('overview.areas', ['Lobby']));
        $this->getJson('/housekeeping/par-levels/consumption?date=2026-10-01&shift=night')->assertOk()->assertJsonPath('consumption.shift', 'night')->assertJsonPath('consumption.rows.0.rooms_serviced', 0)->assertJsonPath('consumption.rows.0.standard', 2);
        $this->getJson('/housekeeping/par-levels/consumption?shift=evening')->assertStatus(422);
        $this->getJson('/housekeeping/par-levels/consumption')->assertStatus(422);
    }

    public function test_the_par_rights_are_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HousekeepingService::VIEW_PERMISSION]);

        $this->get('/housekeeping/par-levels')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false));
        $this->postJson('/housekeeping/par-levels', ['item_id' => $this->item, 'scope_kind' => 'room_type', 'scope_ref' => $this->type, 'par_quantity' => 4, 'use_quantity' => 2, 'reason' => 'x'])->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);
        $this->get('/housekeeping/par-levels')->assertForbidden();
    }
}
