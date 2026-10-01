<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\LinenService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class LinenHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $room;

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
        $this->signIn(self::A, [LinenService::MANAGE_PERMISSION, HousekeepingService::PERFORM_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_items_transfers_and_usage_work_end_to_end_over_http(): void
    {
        $item = $this->postJson('/housekeeping/linen/items', ['code' => 'SHEET_Q', 'name' => 'Queen sheet', 'kind' => 'linen', 'unit' => 'pcs'])->assertCreated()->json('item.id');
        $this->postJson('/housekeeping/linen/items', ['code' => 'SHEET_Q', 'name' => 'Again', 'kind' => 'linen', 'unit' => 'pcs'])->assertStatus(422);

        $sent = $this->postJson('/housekeeping/linen/transfers', ['item_id' => $item, 'from' => 'external', 'to' => 'store', 'quantity' => 50], ['Idempotency-Key' => 'linen-http-send-01'])->assertCreated()->assertJsonPath('transfer.status', 'pending')->json('transfer');
        $again = $this->postJson('/housekeeping/linen/transfers', ['item_id' => $item, 'from' => 'external', 'to' => 'store', 'quantity' => 50], ['Idempotency-Key' => 'linen-http-send-01'])->assertCreated()->json('transfer');
        self::assertSame($sent['id'], $again['id']);
        $this->postJson('/housekeeping/linen/transfers', ['item_id' => $item, 'from' => 'external', 'to' => 'store', 'quantity' => 50])->assertStatus(400);

        $this->getJson('/housekeeping/linen')->assertOk()->assertJsonPath('items.0.in_transit', 50)->assertJsonPath('pending.0.may_receive', false)->assertJsonPath('may.manage', true);
        $this->get('/housekeeping/linen')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/linen')->has('linen.items', 1)->has('linen.pending', 1)->has('linen.locations', 4));

        $this->postJson("/housekeeping/linen/transfers/{$sent['id']}/receive", ['quantity_received' => 50, 'lock_version' => 0])->assertStatus(409);
        $this->postJson("/housekeeping/linen/transfers/{$sent['id']}/cancel", ['lock_version' => 0])->assertOk()->assertJsonPath('transfer.status', 'cancelled');
        $this->postJson("/housekeeping/linen/transfers/{$sent['id']}/cancel", ['lock_version' => 1])->assertStatus(409);

        $this->postJson('/housekeeping/linen/usage', ['room_id' => $this->room, 'item_id' => $item, 'quantity' => 2])->assertCreated();
        $this->getJson('/housekeeping/linen/usage')->assertOk()->assertJsonPath('usage.rows.0.quantity', 2)->assertJsonPath('usage.rows.0.room', '101');
        $this->getJson('/housekeeping/linen/usage?from=2026-01-01&to=2026-10-01')->assertStatus(422);

        $this->postJson("/housekeeping/linen/items/{$item}/active", ['active' => false, 'lock_version' => 0])->assertOk()->assertJsonPath('item.is_active', false);
    }

    public function test_the_right_to_handle_linen_is_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HousekeepingService::VIEW_PERMISSION]);

        $this->get('/housekeeping/linen')->assertOk();
        $this->postJson('/housekeeping/linen/items', ['code' => 'TOWEL_B', 'name' => 'Bath towel', 'kind' => 'linen', 'unit' => 'pcs'])->assertForbidden();
        $this->postJson('/housekeeping/linen/usage', ['room_id' => $this->room, 'item_id' => str_repeat('0', 26), 'quantity' => 1])->assertForbidden();
    }

    public function test_someone_with_no_housekeeping_right_sees_nothing(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);

        $this->get('/housekeeping/linen')->assertForbidden();
    }
}
