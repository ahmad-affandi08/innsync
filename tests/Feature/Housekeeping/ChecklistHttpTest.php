<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\Housekeeping\Application\ChecklistService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ChecklistHttpTest extends TestCase
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
        $this->signIn(self::A, [ChecklistService::MANAGE_PERMISSION, ChecklistService::PERFORM_PERMISSION, ChecklistService::VIEW_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_templates_ticking_and_figures_work_over_http(): void
    {
        $room = $this->postJson('/housekeeping/checklists/templates', ['name' => 'Daily room routine', 'frequency' => 'daily', 'scope' => 'room', 'items' => [['text' => 'Change the linen'], ['text' => 'Clean the bathroom']], 'active' => true])->assertCreated()->assertJsonPath('template.version', 1)->json('template.id');
        $this->postJson('/housekeeping/checklists/templates', ['name' => 'Public area weekly', 'frequency' => 'weekly', 'scope' => 'area', 'areas' => ['Lobby'], 'items' => [['text' => 'Polish the floor']], 'active' => true])->assertCreated();
        $this->postJson('/housekeeping/checklists/templates', ['name' => 'Bad', 'frequency' => 'daily', 'scope' => 'area', 'items' => [['text' => 'x']], 'active' => true])->assertStatus(422);

        $this->get('/housekeeping/checklists')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/checklists')->has('board.checklists', 2)->where('board.may_perform', true));
        $this->get('/housekeeping/checklists/templates')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/checklist-templates')->has('catalogue.templates', 2));

        $this->getJson("/housekeeping/checklists/{$room}/detail?target={$this->room}")->assertOk()->assertJsonPath('checklist.label', '101')->assertJsonPath('checklist.percent', 0);
        $this->postJson("/housekeeping/checklists/{$room}/complete", ['target' => $this->room, 'item_id' => 'i1', 'note' => 'Done'])->assertOk()->assertJsonPath('checklist.percent', 50)->assertJsonPath('checklist.items.0.note', 'Done');
        $this->postJson("/housekeeping/checklists/{$room}/complete", ['target' => $this->room, 'item_id' => 'i1'])->assertStatus(409);
        $this->postJson("/housekeeping/checklists/{$room}/complete", ['target' => $this->room, 'item_id' => 'i7'])->assertStatus(422);
        $this->getJson("/housekeeping/checklists/{$room}/detail?target=nowhere")->assertNotFound();

        $this->get('/housekeeping/checklists/performance')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/checklist-performance')->where('report.percent', 50)->has('report.runs', 1)->has('report.people', 1));
        $this->get('/housekeeping/checklists/performance?from=2026-10-05&to=2026-10-01')->assertStatus(422);
    }

    public function test_a_photo_item_needs_its_photo_over_http_and_the_photo_is_served_privately(): void
    {
        config(['files.disk' => 'local']);
        $template = $this->postJson('/housekeeping/checklists/templates', ['name' => 'Proof', 'frequency' => 'daily', 'scope' => 'room', 'items' => [['text' => 'Photo of the bed', 'photo_required' => true]], 'active' => true])->assertCreated()->assertJsonPath('template.items.0.photo_required', true)->json('template.id');

        $this->post("/housekeeping/checklists/{$template}/complete", ['target' => $this->room, 'item_id' => 'i1'], ['Accept' => 'application/json'])->assertStatus(422);
        $png = UploadedFile::fake()->createWithContent('bed.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $done = $this->post("/housekeeping/checklists/{$template}/complete", ['target' => $this->room, 'item_id' => 'i1', 'photo' => $png], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('checklist.items.0.has_photo', true)->json('checklist.items.0.completion_id');
        $this->get("/housekeeping/checklists/photo/{$done}")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_rights_are_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ChecklistService::VIEW_PERMISSION]);

        $this->get('/housekeeping/checklists')->assertOk();
        $this->get('/housekeeping/checklists/performance')->assertOk();
        $this->get('/housekeeping/checklists/templates')->assertForbidden();
        $this->postJson('/housekeeping/checklists/templates', ['name' => 'Daily room routine', 'frequency' => 'daily', 'scope' => 'room', 'items' => [['text' => 'x']], 'active' => true])->assertForbidden();
        $this->postJson('/housekeeping/checklists/'.str_repeat('0', 26).'/complete', ['target' => 'x', 'item_id' => 'i1'])->assertForbidden();
    }
}
