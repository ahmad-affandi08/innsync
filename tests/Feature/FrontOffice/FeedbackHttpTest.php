<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Feedback\FeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class FeedbackHttpTest extends TestCase
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
        $this->signIn(self::A, [FeedbackService::MANAGE_PERMISSION, 'property.settings.manage']);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_feedback_is_recorded_followed_up_and_closed_through_http(): void
    {
        $body = ['kind' => 'complaint', 'severity' => 'high', 'channel' => 'phone', 'guest_name' => 'Ms. Sari', 'summary' => 'Noise from the next room', 'detail' => 'Until 2 am', 'follow_up_by' => '2026-10-02'];
        $item = $this->postJson('/front-office/feedback', $body, ['Idempotency-Key' => 'feedback-key-000001'])->assertCreated()->assertJsonPath('item.number', 'FDB-000001')->json('item');
        $this->postJson('/front-office/feedback', $body, ['Idempotency-Key' => 'feedback-key-000001'])->assertCreated();
        self::assertSame(1, DB::table('guest_feedback')->count());
        $this->postJson('/front-office/feedback', [...$body, 'follow_up_by' => null], ['Idempotency-Key' => 'feedback-key-000002'])->assertStatus(422);

        $me = (string) DB::table('users')->value('id');
        $this->postJson("/front-office/feedback/{$item['id']}/start", ['lock_version' => 0])->assertStatus(409);
        $this->postJson("/front-office/feedback/{$item['id']}/assign", ['owner_id' => $me, 'lock_version' => 0])->assertOk()->assertJsonPath('item.owner_id', strtolower($me));
        $this->postJson("/front-office/feedback/{$item['id']}/start", ['lock_version' => 1])->assertOk()->assertJsonPath('item.status', 'in_progress');
        $this->postJson("/front-office/feedback/{$item['id']}/note", ['text' => 'Moved the guest to another floor'])->assertOk();
        $this->postJson("/front-office/feedback/{$item['id']}/resolve", ['resolution' => 'Moved to 301 and apologised', 'lock_version' => 2])->assertStatus(422);
        $this->postJson("/front-office/feedback/{$item['id']}/resolve", ['resolution' => 'Moved to 301 and apologised', 'evidence_ref' => 'Signed note SN-7', 'lock_version' => 2])->assertOk()->assertJsonPath('item.status', 'resolved');
        $this->postJson("/front-office/feedback/{$item['id']}/close", ['lock_version' => 3])->assertOk()->assertJsonPath('item.status', 'closed');

        $this->get("/front-office/feedback/{$item['id']}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/feedback-item')->where('detail.item.status', 'closed')->where('detail.item.evidence_ref', 'Signed note SN-7')->has('detail.events', 6)->where('detail.may_manage', true));
        $this->get('/front-office/feedback')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/feedback')->has('queue.items', 0)->where('filters.status', 'active'));
        $this->get('/front-office/feedback?status=closed&kind=complaint')->assertInertia(fn (Assert $p) => $p->has('queue.items', 1));
        $this->get('/front-office/feedback?status=')->assertInertia(fn (Assert $p) => $p->has('queue.items', 1));
    }

    public function test_viewers_see_but_cannot_act_and_strangers_see_nothing(): void
    {
        $item = $this->postJson('/front-office/feedback', ['kind' => 'compliment', 'channel' => 'online', 'guest_name' => 'A guest', 'summary' => 'Great stay'], ['Idempotency-Key' => 'feedback-key-000010'])->assertCreated()->json('item');

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [FeedbackService::VIEW_PERMISSION]);
        $this->get('/front-office/feedback')->assertInertia(fn (Assert $p) => $p->where('queue.may_manage', false)->has('queue.items', 1));
        $this->get("/front-office/feedback/{$item['id']}")->assertInertia(fn (Assert $p) => $p->where('detail.may_manage', false));
        $this->postJson('/front-office/feedback', ['kind' => 'compliment', 'channel' => 'online', 'summary' => 'x'], ['Idempotency-Key' => 'feedback-key-000011'])->assertForbidden();
        $this->postJson("/front-office/feedback/{$item['id']}/note", ['text' => 'x'])->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['housekeeping.view']);
        $this->get('/front-office/feedback')->assertForbidden();
    }
}
