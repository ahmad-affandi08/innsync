<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Housekeeping\Application\LostFoundService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class LostFoundHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

        config(['files.disk' => 'local']);
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [LostFoundService::RECORD_PERMISSION, LostFoundService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_an_item_is_recorded_with_a_photo_viewed_and_returned_over_http(): void
    {
        $photo = UploadedFile::fake()->createWithContent('wallet.png', (string) base64_decode(self::PNG, true));
        $item = $this->post('/housekeeping/lost-found', ['description' => 'Black wallet', 'room_id' => $this->room, 'stored_at' => 'Office', 'photo' => $photo], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('item.number', 'LF-000001')->json('item');

        $this->get('/housekeeping/lost-found')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/lost-found')->has('overview.items', 1)->where('overview.items.0.has_photo', true)->where('overview.may.manage', true)->has('overview.rooms', 1));
        $this->get("/housekeeping/lost-found/{$item['id']}/photo")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/housekeeping/lost-found', ['description' => 'No place', 'stored_at' => 'Office'])->assertStatus(422);
        $this->postJson("/housekeeping/lost-found/{$item['id']}/returned", ['returned_to' => 'Mr Budi', 'note' => 'ID checked', 'lock_version' => 0])->assertOk()->assertJsonPath('item.status', 'returned');
        $this->postJson("/housekeeping/lost-found/{$item['id']}/returned", ['returned_to' => 'Again', 'lock_version' => 1])->assertStatus(409);
        $this->get('/housekeeping/lost-found?status=returned')->assertInertia(fn (Assert $p) => $p->has('overview.items', 1)->where('status', 'returned'));
        $this->get('/housekeeping/lost-found?status=stored')->assertInertia(fn (Assert $p) => $p->has('overview.items', 0));
    }

    public function test_viewing_housekeeping_alone_lists_items_but_changes_nothing(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HousekeepingService::VIEW_PERMISSION]);

        $this->get('/housekeeping/lost-found')->assertOk();
        $this->postJson('/housekeeping/lost-found', ['description' => 'Wallet', 'place' => 'Lobby', 'stored_at' => 'Office'])->assertForbidden();
        $this->postJson('/housekeeping/lost-found/'.str_repeat('0', 26).'/disposed', ['reason' => 'x', 'lock_version' => 0])->assertForbidden();
    }
}
