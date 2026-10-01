<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Routine\ShiftLogService;
use App\Modules\FrontOffice\Application\Routine\SopService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class RoutineHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

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
        $this->signIn(self::A, [SopService::MANAGE_PERMISSION, SopService::PERFORM_PERMISSION, SopService::VIEW_PERMISSION, ShiftLogService::WRITE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_a_checklist_is_written_ticked_and_measured_through_http(): void
    {
        $t = $this->postJson('/front-office/checklists/templates', ['name' => 'Opening routine', 'frequency' => 'daily', 'items' => ['Count the float', 'Read the log book'], 'active' => true])->assertCreated()->assertJsonPath('template.version', 1)->json('template');
        $this->postJson('/front-office/checklists/templates', ['name' => 'Opening routine', 'frequency' => 'weekly', 'items' => ['x'], 'active' => true])->assertStatus(422);
        $this->postJson('/front-office/checklists/templates', ['name' => 'Empty', 'frequency' => 'daily', 'items' => [], 'active' => true])->assertStatus(422);

        $this->get('/front-office/checklists')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/checklists')->where('board.business_date', '2026-10-01')->has('board.checklists', 1)->where('board.checklists.0.total', 2)->where('board.may_perform', true));
        $this->postJson("/front-office/checklists/{$t['id']}/items/i1/complete", ['note' => 'Float 500,000'])->assertOk()->assertJsonPath('checklist.percent', 50)->assertJsonPath('checklist.items.0.done', true);
        $this->postJson("/front-office/checklists/{$t['id']}/items/i1/complete")->assertStatus(409);
        $this->postJson("/front-office/checklists/{$t['id']}/items/i2/complete")->assertOk()->assertJsonPath('checklist.percent', 100);

        $this->get('/front-office/checklists/templates')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/checklist-templates')->has('catalogue.templates', 1));
        $this->get('/front-office/checklists/performance?from=2026-10-01&to=2026-10-01')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/checklist-performance')->where('report.runs.0.percent', 100)->where('report.people.0.items', 2));
        $this->get('/front-office/checklists/performance?from=2026-10-02&to=2026-10-01')->assertStatus(422);
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'frontoffice.sop.item_completed')->count());
    }

    public function test_the_log_book_is_written_and_read_through_http(): void
    {
        $entry = $this->postJson('/front-office/logbook', ['shift' => 'night', 'body' => 'Guest in 102 wants a taxi at 5', 'important' => true])->assertCreated()->assertJsonPath('entry.priority', 'important')->json('entry');
        $this->postJson('/front-office/logbook', ['shift' => 'dawn', 'body' => 'x'])->assertStatus(422);

        $this->get('/front-office/logbook')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/logbook')->has('log.entries', 1)->where('log.unread', 0)->where('log.may_write', true));

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ShiftLogService::READ_PERMISSION]);
        $this->get('/front-office/logbook')->assertInertia(fn (Assert $p) => $p->where('log.unread', 1)->where('log.may_write', false));
        $this->postJson('/front-office/logbook', ['shift' => 'night', 'body' => 'x'])->assertForbidden();
        $this->postJson('/front-office/logbook/read', ['entries' => [$entry['id']]])->assertOk()->assertJsonPath('marked', 1);
        $this->get('/front-office/logbook')->assertInertia(fn (Assert $p) => $p->where('log.unread', 0));
        $this->get('/front-office/checklists')->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['housekeeping.view']);
        $this->get('/front-office/logbook')->assertForbidden();
    }
}
