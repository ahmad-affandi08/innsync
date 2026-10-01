<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ApprovalHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private UserRecord $maker;

    private UserRecord $supervisor;

    private UserRecord $outsider;

    private string $requestId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['approvals.subjects' => ['fnb.bill.void' => ['mandatory' => true]]]);
        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');

        $admin = UserRecord::factory()->create();
        $this->maker = UserRecord::factory()->create();
        $this->supervisor = UserRecord::factory()->create();
        $this->outsider = UserRecord::factory()->create();
        $this->grant($admin, self::A, [ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->grant($this->maker, self::A, ['fnb.bill.request-void']);
        $this->grant($this->supervisor, self::A, ['fnb.void.approve']);
        $this->grant($this->outsider, self::A, ['front-office.reservation.view']);

        $context = app(PropertyContext::class);
        $context->activate(PropertyId::fromString(self::A));

        app(ApprovalPolicyAdmin::class)->define(PropertyId::fromString(self::A), strtolower((string) $admin->getKey()), 'fnb.bill.void', 0, [['permission' => 'fnb.void.approve']], 'Initial');
        $this->requestId = app(ApprovalService::class)->request(new ApprovalRequestInput(
            PropertyId::fromString(self::A), 'fnb.bill.void', 'bill-77', strtolower((string) $this->maker->getKey()),
            'Guest complaint', ['bill' => 'bill-77'], ['total_minor' => 150000], 150000, 'IDR',
        ), IdempotencyKey::fromString('request-key-0000001'))->id;
    }

    private function as(UserRecord $user): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function confirm(): void
    {
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
    }

    public function test_the_inbox_shows_only_what_the_signed_in_person_may_decide_and_their_own_requests(): void
    {
        $this->as($this->supervisor);
        $this->get('/approvals')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('identity-access/pages/approvals')
            ->has('pending', 1, fn (Assert $row) => $row->where('id', $this->requestId)->where('status', 'pending')->etc())
            ->has('mine', 0));

        $this->as($this->maker);
        $this->get('/approvals')->assertInertia(fn (Assert $page) => $page->has('pending', 0)->has('mine', 1));

        $this->as($this->outsider);
        $this->get('/approvals')->assertInertia(fn (Assert $page) => $page->has('pending', 0)->has('mine', 0));
    }

    public function test_deciding_needs_a_recent_password_confirmation_and_a_signed_in_user(): void
    {
        $this->postJson("/approvals/{$this->requestId}/approve")->assertStatus(401);

        $this->as($this->supervisor);
        // Signing in counts as a fresh confirmation; the window is simulated as expired.
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->postJson("/approvals/{$this->requestId}/approve")->assertStatus(423);
        self::assertSame(0, DB::table('approval_decisions')->count());

        $this->confirm();
        $this->postJson("/approvals/{$this->requestId}/approve")
            ->assertOk()
            ->assertJsonPath('approval.status', 'approved')
            ->assertJsonPath('approval.decisions.0.approver_id', strtolower((string) $this->supervisor->getKey()));

        self::assertSame(1, DB::table('approval_decisions')->count());
    }

    public function test_the_reconfirm_route_returns_to_the_inbox_once_the_password_is_confirmed(): void
    {
        $this->as($this->supervisor);
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->get('/approvals/confirm')->assertRedirect(route('password.confirm'));

        $this->confirm();
        $this->get('/approvals/confirm')->assertRedirect(route('approvals.index'));
    }

    public function test_the_server_refuses_self_approval_and_ineligible_approvers_with_the_standard_error(): void
    {
        $this->grant($this->maker, self::A, ['fnb.void.approve']);

        $this->as($this->maker);
        $this->confirm();
        $this->postJson("/approvals/{$this->requestId}/approve")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->as($this->outsider);
        $this->confirm();
        $this->postJson("/approvals/{$this->requestId}/approve")->assertForbidden();
        $this->postJson("/approvals/{$this->requestId}/reject", ['reason' => 'x'])->assertForbidden();

        self::assertSame(0, DB::table('approval_decisions')->count());
        self::assertSame(3, DB::table('security_events')->where('event_type', 'identity.approval.denied')->count());
        $this->assertDatabaseHas('approval_requests', ['id' => $this->requestId, 'status' => 'pending']);
    }

    public function test_a_rejection_needs_a_reason_and_a_repeated_approval_is_a_harmless_retry(): void
    {
        $this->as($this->supervisor);
        $this->confirm();

        $this->postJson("/approvals/{$this->requestId}/reject", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['reason']]]);

        $this->postJson("/approvals/{$this->requestId}/approve")->assertOk();
        $this->postJson("/approvals/{$this->requestId}/approve")->assertOk()->assertJsonPath('approval.status', 'approved');
        self::assertSame(1, DB::table('approval_decisions')->count());

        // Once final, a different decision is a conflict that tells the client to refresh.
        $this->postJson("/approvals/{$this->requestId}/reject", ['reason' => 'too late'])
            ->assertStatus(409)
            ->assertJsonPath('error.conflict.reason', 'approval_state')
            ->assertJsonPath('error.conflict.action', 'refresh');
    }

    public function test_an_unknown_or_foreign_request_is_not_found_and_details_are_limited_to_those_involved(): void
    {
        $this->as($this->supervisor);
        $this->confirm();

        $this->postJson('/approvals/01arz3ndektsv4rrffq69g5fzz/approve')->assertNotFound();
        $this->getJson("/approvals/{$this->requestId}")->assertOk()->assertJsonPath('approval.payload.bill', 'bill-77');

        $this->as($this->outsider);
        $this->getJson("/approvals/{$this->requestId}")->assertNotFound();
        $this->getJson('/approvals/not-an-id')->assertNotFound();

        $this->as($this->maker);
        $this->getJson("/approvals/{$this->requestId}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_maker_can_cancel_but_nobody_else_can(): void
    {
        $this->as($this->supervisor);
        $this->postJson("/approvals/{$this->requestId}/cancel")->assertForbidden();

        $this->as($this->maker);
        $this->postJson("/approvals/{$this->requestId}/cancel")->assertOk()->assertJsonPath('approval.status', 'cancelled');
        $this->assertDatabaseHas('audit_entries', ['action' => 'approval.cancelled', 'approval_reference' => $this->requestId]);
    }

    public function test_a_web_form_submission_redirects_back_instead_of_returning_json(): void
    {
        $this->as($this->supervisor);
        $this->confirm();

        $this->from('/approvals')->post("/approvals/{$this->requestId}/approve")->assertRedirect('/approvals');
        $this->assertDatabaseHas('approval_requests', ['id' => $this->requestId, 'status' => 'approved']);
    }
}
