<?php

declare(strict_types=1);

namespace Tests\Integration\IdentityAccess;

use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalRefused;
use App\Modules\IdentityAccess\Application\Approval\ApprovalRepository;
use App\Modules\IdentityAccess\Application\Approval\ApprovalRequestNotFound;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Application\Approval\NotAnEligibleApprover;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Approval\ApprovalNotUsable;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\MissingApprovalPolicy;
use App\Shared\Application\Approval\UnknownApprovalSubject;
use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;
use Throwable;

final class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private const OUTLET = '01arz3ndektsv4rrffq69g5fb5';

    private UserRecord $maker;

    private UserRecord $supervisor;

    private UserRecord $manager;

    private UserRecord $outsider;

    private UserRecord $admin;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['approvals.subjects' => [
            'fnb.bill.void' => ['mandatory' => true],
            'fnb.bill.discount' => ['mandatory' => false],
        ]]);

        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');

        $this->maker = UserRecord::factory()->create();
        $this->supervisor = UserRecord::factory()->create();
        $this->manager = UserRecord::factory()->create();
        $this->outsider = UserRecord::factory()->create();
        $this->admin = UserRecord::factory()->create();

        $this->grant($this->maker, self::A, ['fnb.bill.request-void']);
        $this->grant($this->supervisor, self::A, ['fnb.void.approve']);
        $this->grant($this->manager, self::A, ['fnb.void.approve', 'finance.refund.approve']);
        $this->grant($this->admin, self::A, [ApprovalPolicyAdmin::MANAGE_PERMISSION]);

        $this->inProperty(self::A);
    }

    private function inProperty(string $id): void
    {
        $context = app(PropertyContext::class);
        $context->clear();
        $context->activate(PropertyId::fromString($id));
    }

    private function service(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    private function id(UserRecord $user): string
    {
        return strtolower((string) $user->getKey());
    }

    private function policy(string $type = 'fnb.bill.void', int $band = 0, ?array $steps = null): void
    {
        app(ApprovalPolicyAdmin::class)->define(
            PropertyId::fromString(self::A),
            $this->id($this->admin),
            $type,
            $band,
            $steps ?? [['permission' => 'fnb.void.approve']],
            'Initial configuration',
        );
    }

    /** @param array<string, mixed> $override */
    private function input(array $override = []): ApprovalRequestInput
    {
        $args = array_merge([
            'propertyId' => PropertyId::fromString(self::A),
            'subjectType' => 'fnb.bill.void',
            'subjectRef' => 'bill-77',
            'makerId' => $this->id($this->maker),
            'reason' => 'Guest complaint',
            'payload' => ['bill' => 'bill-77', 'void_items' => [1, 2]],
            'before' => ['total_minor' => 150000],
            'amountMinor' => 150000,
            'currency' => 'IDR',
        ], $override);

        return new ApprovalRequestInput(...$args);
    }

    private function open(string $key = 'request-key-0000001', array $override = []): string
    {
        return $this->service()->request($this->input($override), IdempotencyKey::fromString($key))->id;
    }

    private function propertyA(): PropertyId
    {
        return PropertyId::fromString(self::A);
    }

    // ------------------------------------------------------------------ policy and requirement

    public function test_requirement_follows_the_declared_subjects_and_configured_policies(): void
    {
        $gate = $this->service();

        try {
            $gate->requirementFor($this->propertyA(), 'fnb.bill.typo');
            self::fail('An undeclared subject must be refused, so a typo cannot skip approval.');
        } catch (UnknownApprovalSubject) {
            $this->addToAssertionCount(1);
        }

        // Mandatory with no policy: fail closed, never "not required".
        $this->expectException(MissingApprovalPolicy::class);

        try {
            $gate->requirementFor($this->propertyA(), 'fnb.bill.void', 1000);
        } finally {
            self::assertFalse($gate->requirementFor($this->propertyA(), 'fnb.bill.discount')->required, 'an optional subject without a policy needs no approval');
        }
    }

    public function test_amount_bands_pick_the_highest_matching_chain(): void
    {
        $this->policy('fnb.bill.void', 0, [['permission' => 'fnb.void.approve']]);
        $this->policy('fnb.bill.void', 500000, [['permission' => 'fnb.void.approve'], ['permission' => 'finance.refund.approve']]);
        $gate = $this->service();

        $low = $gate->request($this->input(['amountMinor' => 100000, 'subjectRef' => 'b1']), IdempotencyKey::fromString('band-key-00000001'));
        $edge = $gate->request($this->input(['amountMinor' => 500000, 'subjectRef' => 'b2']), IdempotencyKey::fromString('band-key-00000002'));
        $none = $gate->request($this->input(['amountMinor' => null, 'subjectRef' => 'b3']), IdempotencyKey::fromString('band-key-00000003'));

        self::assertCount(1, $low->steps);
        self::assertCount(2, $edge->steps, 'the band starts at its threshold');
        self::assertCount(1, $none->steps, 'a missing amount matches only the base band');
    }

    public function test_changing_a_policy_is_versioned_audited_and_leaves_open_requests_on_their_snapshot(): void
    {
        $this->policy();
        $id = $this->open();

        $this->policy('fnb.bill.void', 0, [['permission' => 'fnb.void.approve'], ['permission' => 'finance.refund.approve']]);

        self::assertSame(2, DB::table('approval_policies')->where('subject_type', 'fnb.bill.void')->count());
        self::assertSame(1, DB::table('approval_policies')->whereNull('superseded_at')->count(), 'exactly one active version');
        self::assertCount(1, $this->service()->find($this->propertyA(), $id)->steps, 'the open request keeps the policy it was opened under');

        $changes = DB::table('audit_entries')->where('action', 'approval.policy.changed')->orderBy('occurred_at')->get();
        self::assertCount(2, $changes);
        self::assertNull(json_decode((string) $changes[0]->before_state, true));
        self::assertCount(1, json_decode((string) $changes[1]->before_state, true)['steps']);
        self::assertCount(2, json_decode((string) $changes[1]->after_state, true)['steps']);
        self::assertSame('Initial configuration', $changes[1]->reason);
    }

    public function test_only_a_person_with_the_manage_permission_can_define_policy_and_input_is_validated(): void
    {
        $admin = app(ApprovalPolicyAdmin::class);

        try {
            $admin->define($this->propertyA(), $this->id($this->supervisor), 'fnb.bill.void', 0, [['permission' => 'fnb.void.approve']], 'x');
            self::fail('Without the manage permission nobody may change approval policy.');
        } catch (NotAnEligibleApprover) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            fn () => $admin->define($this->propertyA(), $this->id($this->admin), 'fnb.bill.void', 0, [], 'x'),
            fn () => $admin->define($this->propertyA(), $this->id($this->admin), 'fnb.bill.void', 0, [['permission' => 'Not A Code']], 'x'),
            fn () => $admin->define($this->propertyA(), $this->id($this->admin), 'fnb.bill.void', 0, [['permission' => 'a.b', 'approvals_required' => 9]], 'x'),
            fn () => $admin->define($this->propertyA(), $this->id($this->admin), 'fnb.bill.void', 0, [['permission' => 'a.b']], '  '),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid policy input must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(UnknownApprovalSubject::class);
        $admin->define($this->propertyA(), $this->id($this->admin), 'not.declared', 0, [['permission' => 'a.b']], 'x');
    }

    // ------------------------------------------------------------------ the request

    public function test_a_request_is_recorded_with_a_policy_snapshot_audit_and_is_idempotent(): void
    {
        $this->policy();

        $first = $this->service()->request($this->input(), IdempotencyKey::fromString('request-key-0000001'));
        $retry = $this->service()->request($this->input(), IdempotencyKey::fromString('request-key-0000001'));

        self::assertSame('pending', $first->status);
        self::assertSame($first->id, $retry->id, 'a retry with the same key returns the same request');
        self::assertSame(1, DB::table('approval_requests')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'approval.requested')->count());

        $audit = DB::table('audit_entries')->where('action', 'approval.requested')->first();
        self::assertSame($first->id, $audit->approval_reference);
        self::assertSame('Guest complaint', $audit->reason);
        self::assertSame(hash('sha256', (string) json_encode(['bill' => 'bill-77', 'void_items' => [1, 2]])), json_decode((string) $audit->after_state, true)['payload_hash']);

        $this->expectException(IdempotencyConflict::class);
        $this->service()->request($this->input(['payload' => ['bill' => 'bill-77', 'void_items' => [9]]]), IdempotencyKey::fromString('request-key-0000001'));
    }

    public function test_a_request_without_an_applicable_policy_or_for_another_property_is_refused(): void
    {
        try {
            $this->open();
            self::fail('A mandatory subject without policy must fail closed.');
        } catch (MissingApprovalPolicy) {
            $this->addToAssertionCount(1);
        }

        $this->policy();
        $this->expectException(PropertyScopeViolation::class);
        $this->service()->request($this->input(['propertyId' => PropertyId::fromString(self::B)]), IdempotencyKey::fromString('other-key-00000001'));
    }

    public function test_sensitive_or_float_evidence_is_refused_before_anything_is_stored(): void
    {
        $this->policy();

        foreach ([['card_number' => '4111'], ['nested' => ['password' => 'x']]] as $payload) {
            try {
                $this->input(['payload' => $payload]);
                self::fail('Sensitive evidence must be refused.');
            } catch (Throwable $e) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->input(['payload' => ['discount' => 0.1]])->payloadHash();
            self::fail('Floats are refused: money is integer minor units.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, DB::table('approval_requests')->count());
    }

    // ------------------------------------------------------------------ deciding

    public function test_an_eligible_other_person_approves_with_full_evidence_and_the_outcome_is_published(): void
    {
        $this->policy();
        $id = $this->open();

        $view = $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));

        self::assertSame('approved', $view->status);
        self::assertCount(1, $view->decisions);
        self::assertSame($this->id($this->supervisor), $view->decisions[0]['approver_id']);
        self::assertSame(1, DB::table('approval_decisions')->count());

        $audit = DB::table('audit_entries')->where('action', 'approval.approved')->first();
        self::assertSame($id, $audit->approval_reference);
        self::assertSame($this->id($this->supervisor), $audit->actor_id);
        self::assertSame('pending', json_decode((string) $audit->before_state, true)['status']);
        self::assertSame('approved', json_decode((string) $audit->after_state, true)['status']);

        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'identity.approval.decided')->count());
    }

    public function test_the_maker_cannot_approve_even_when_they_hold_the_approver_permission(): void
    {
        $this->grant($this->maker, self::A, ['fnb.void.approve']);
        $this->policy();
        $id = $this->open();

        try {
            $this->service()->approve($this->propertyA(), $id, $this->id($this->maker));
            self::fail('Self-approval must be refused.');
        } catch (ApprovalRefused $refused) {
            self::assertSame('self_approval', $refused->reasonCode);
            self::assertSame(403, $refused->status());
        }

        self::assertSame('pending', $this->service()->find($this->propertyA(), $id)->status);
        self::assertSame(0, DB::table('approval_decisions')->count());
        // The attempt itself is kept as evidence, even though the decision was rolled back.
        $event = DB::table('security_events')->where('event_type', 'identity.approval.denied')->first();
        self::assertNotNull($event);
        self::assertSame('self_approval', json_decode((string) $event->metadata, true)['reason_code']);
    }

    public function test_a_person_without_the_step_permission_cannot_approve_or_reject(): void
    {
        $this->policy();
        $id = $this->open();

        foreach ([
            fn () => $this->service()->approve($this->propertyA(), $id, $this->id($this->outsider)),
            fn () => $this->service()->reject($this->propertyA(), $id, $this->id($this->outsider), 'no'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('An ineligible approver must be refused.');
            } catch (NotAnEligibleApprover) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(0, DB::table('approval_decisions')->count());
        self::assertSame(2, DB::table('security_events')->where('event_type', 'identity.approval.denied')->count());
    }

    public function test_a_chain_needs_different_people_and_the_right_permission_at_each_step(): void
    {
        $this->policy('fnb.bill.void', 0, [['permission' => 'fnb.void.approve'], ['permission' => 'finance.refund.approve']]);
        $id = $this->open();

        // A supervisor cannot skip ahead: step 2 needs the refund permission, which they lack.
        $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));
        $mid = $this->service()->find($this->propertyA(), $id);
        self::assertSame('pending', $mid->status);
        self::assertSame(1, $mid->currentStep);

        // The supervisor repeating their approval is a harmless retry: it adds nothing and does not advance the chain.
        $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));
        self::assertSame(1, DB::table('approval_decisions')->count());
        self::assertSame(1, $this->service()->find($this->propertyA(), $id)->currentStep);

        $done = $this->service()->approve($this->propertyA(), $id, $this->id($this->manager));
        self::assertSame('approved', $done->status);
        self::assertSame(2, DB::table('approval_decisions')->count());
    }

    public function test_the_same_decision_repeated_is_a_no_op_and_cannot_be_flipped(): void
    {
        $this->policy('fnb.bill.void', 0, [['permission' => 'fnb.void.approve', 'approvals_required' => 2]]);
        $id = $this->open();

        $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));
        $again = $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));

        self::assertSame('pending', $again->status);
        self::assertSame(1, DB::table('approval_decisions')->count(), 'a retry adds no second decision');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'approval.approved')->count());

        try {
            $this->service()->reject($this->propertyA(), $id, $this->id($this->supervisor), 'changed my mind');
            self::fail('A decision cannot be reversed by the same person.');
        } catch (ApprovalRefused $refused) {
            self::assertSame('already_decided', $refused->reasonCode);
            self::assertSame(409, $refused->status());
        }
    }

    public function test_a_rejection_needs_a_reason_ends_the_request_and_is_published(): void
    {
        $this->policy();
        $id = $this->open();

        try {
            $this->service()->reject($this->propertyA(), $id, $this->id($this->supervisor), '  ');
            self::fail('A rejection needs a reason.');
        } catch (ApprovalRefused $refused) {
            self::assertSame(422, $refused->status());
            self::assertSame(['reason'], $refused->invalidFields());
        }

        $view = $this->service()->reject($this->propertyA(), $id, $this->id($this->supervisor), 'Not justified');

        self::assertSame('rejected', $view->status);
        self::assertSame('Not justified', $view->decisions[0]['reason']);
        self::assertSame('Not justified', DB::table('audit_entries')->where('action', 'approval.rejected')->value('reason'));

        $this->expectException(ApprovalRefused::class);
        $this->service()->approve($this->propertyA(), $id, $this->id($this->manager));
    }

    public function test_two_approvers_deciding_at_once_cannot_overwrite_each_other(): void
    {
        $this->policy();
        $id = $this->open();
        $repository = app(ApprovalRepository::class);

        // Both load the pending request before either saves.
        $first = $repository->find($this->propertyA(), $id);
        $second = $repository->find($this->propertyA(), $id);

        $first->approve($this->id($this->supervisor), new \DateTimeImmutable);
        $second->approve($this->id($this->manager), new \DateTimeImmutable);

        $repository->save($first, '01arz3ndektsv4rrffq69g5fc1');

        try {
            $repository->save($second, '01arz3ndektsv4rrffq69g5fc1');
            self::fail('The stale decision must not win.');
        } catch (OptimisticLockConflict) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(1, DB::table('approval_decisions')->count(), 'the losing decision left no trace');
        self::assertSame(1, (int) DB::table('approval_requests')->value('lock_version'));
    }

    // ------------------------------------------------------------------ cancel, supersede, consume

    public function test_only_the_maker_can_cancel_and_only_while_pending(): void
    {
        $this->policy();
        $id = $this->open();

        try {
            $this->service()->cancel($this->propertyA(), $id, $this->id($this->supervisor));
            self::fail('Only the maker can cancel.');
        } catch (ApprovalRefused $refused) {
            self::assertSame('not_maker', $refused->reasonCode);
        }

        self::assertSame('cancelled', $this->service()->cancel($this->propertyA(), $id, $this->id($this->maker))->status);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'approval.cancelled')->count());

        $this->expectException(ApprovalRefused::class);
        $this->service()->cancel($this->propertyA(), $id, $this->id($this->maker));
    }

    public function test_a_material_change_supersedes_the_open_request_with_a_new_one(): void
    {
        $this->policy();
        $old = $this->open('request-key-0000001');

        $new = $this->service()->request(
            $this->input(['payload' => ['bill' => 'bill-77', 'void_items' => [1, 2, 3]], 'supersedes' => $old]),
            IdempotencyKey::fromString('request-key-0000002'),
        );

        self::assertSame('cancelled', $this->service()->find($this->propertyA(), $old)->status);
        self::assertSame('pending', $new->status);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'approval.superseded')->count());

        // Another subject's request cannot be superseded.
        $other = $this->open('request-key-0000003', ['subjectRef' => 'bill-99']);
        $this->expectException(ApprovalRefused::class);
        $this->service()->request($this->input(['supersedes' => $other, 'payload' => ['x' => 1]]), IdempotencyKey::fromString('request-key-0000004'));
    }

    public function test_an_approval_is_consumed_once_by_the_maker_for_exactly_what_was_approved(): void
    {
        $this->policy();
        $id = $this->open();
        $payload = ['bill' => 'bill-77', 'void_items' => [1, 2]];
        $gate = $this->service();
        $consume = fn (?array $p = null, ?string $actor = null, string $ref = 'bill-77', string $type = 'fnb.bill.void') => $gate->consume($this->propertyA(), $id, $type, $ref, $p ?? $payload, $actor ?? $this->id($this->maker));

        $reasons = [];
        $attempt = function (callable $act) use (&$reasons): void {
            try {
                $act();
            } catch (ApprovalNotUsable $e) {
                $reasons[] = $e->reasonCode;
            }
        };

        $attempt($consume);
        $gate->approve($this->propertyA(), $id, $this->id($this->supervisor));
        $attempt(fn () => $consume(actor: $this->id($this->supervisor)));
        $attempt(fn () => $consume(['bill' => 'bill-77', 'void_items' => [1, 2, 3]]));
        $attempt(fn () => $consume(ref: 'bill-78'));
        $attempt(fn () => $consume(type: 'fnb.bill.discount'));

        self::assertSame(['not_approved', 'not_maker', 'payload_mismatch', 'subject_mismatch', 'subject_mismatch'], $reasons);
        self::assertFalse($gate->find($this->propertyA(), $id)->consumed, 'refused attempts consume nothing');
        self::assertSame(5, DB::table('security_events')->where('event_type', 'identity.approval.denied')->count(), 'every refused attempt is evidence');

        // Key order of the payload does not matter.
        $view = $consume(['void_items' => [1, 2], 'bill' => 'bill-77']);
        self::assertTrue($view->consumed);
        self::assertSame($id, DB::table('audit_entries')->where('action', 'approval.consumed')->value('approval_reference'));

        $attempt($consume);
        self::assertSame('already_consumed', end($reasons), 'single use');
    }

    public function test_an_unknown_request_and_a_request_of_another_property_look_identical(): void
    {
        $this->policy();
        $id = $this->open();

        $this->inProperty(self::B);
        self::assertNull($this->service()->find(PropertyId::fromString(self::B), $id));

        try {
            $this->service()->approve(PropertyId::fromString(self::B), $id, $this->id($this->supervisor));
            self::fail('A foreign request must be indistinguishable from a missing one.');
        } catch (ApprovalRequestNotFound $notFound) {
            self::assertSame(404, $notFound->status());
        }

        $this->expectException(PropertyScopeViolation::class);
        $this->service()->find($this->propertyA(), $id);
    }

    // ------------------------------------------------------------------ inbox and visibility

    public function test_the_inbox_lists_only_what_a_person_may_decide_now(): void
    {
        $this->policy();
        $this->open('request-key-0000001', ['subjectRef' => 'b1']);
        $this->service()->request($this->input(['subjectRef' => 'b2', 'makerId' => $this->id($this->supervisor)]), IdempotencyKey::fromString('request-key-0000002'));
        $this->open('request-key-0000003', ['subjectRef' => 'b3']);
        $this->policy('fnb.bill.void', 0, [['permission' => 'fnb.void.approve', 'approvals_required' => 2]]);
        $twoStep = $this->open('request-key-0000004', ['subjectRef' => 'b4']);
        $this->service()->approve($this->propertyA(), $twoStep, $this->id($this->supervisor));

        $ids = fn (UserRecord $u) => array_map(fn ($v) => $v->subjectRef, $this->service()->pendingFor($this->propertyA(), $this->id($u)));

        self::assertSame(['b1', 'b2', 'b3', 'b4'], $ids($this->manager), 'a manager sees every pending request, including the supervisor\'s');
        self::assertSame(['b1', 'b3'], $ids($this->supervisor), 'not the one they made, not the one they already approved');
        self::assertSame([], $ids($this->outsider), 'no permission, no inbox');
        self::assertSame([], $ids($this->maker), 'the maker holds no approver permission');
    }

    public function test_an_outlet_scoped_request_can_only_be_decided_by_an_approver_of_that_outlet(): void
    {
        $outletSupervisor = UserRecord::factory()->create();
        $otherOutlet = UserRecord::factory()->create();
        $this->grant($outletSupervisor, self::A, ['fnb.void.approve'], 'outlet', self::OUTLET);
        $this->grant($otherOutlet, self::A, ['fnb.void.approve'], 'outlet', '01arz3ndektsv4rrffq69g5fb6');
        $this->policy();

        $id = $this->open('request-key-0000001', ['scopeType' => 'outlet', 'scopeId' => self::OUTLET]);

        self::assertCount(1, $this->service()->pendingFor($this->propertyA(), $this->id($outletSupervisor)));
        self::assertCount(0, $this->service()->pendingFor($this->propertyA(), $this->id($otherOutlet)));
        // A property-wide approver outranks outlet scope.
        self::assertCount(1, $this->service()->pendingFor($this->propertyA(), $this->id($this->supervisor)));

        $this->expectException(NotAnEligibleApprover::class);
        $this->service()->approve($this->propertyA(), $id, $this->id($otherOutlet));
    }

    public function test_a_request_is_visible_only_to_those_involved_or_able_to_decide(): void
    {
        $this->policy();
        $id = $this->open();
        $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));

        $see = fn (UserRecord $u) => $this->service()->visibleTo($this->propertyA(), $id, $this->id($u)) !== null;

        self::assertTrue($see($this->maker));
        self::assertTrue($see($this->supervisor), 'the approver who decided');
        self::assertFalse($see($this->outsider));
        self::assertFalse($see($this->manager), 'a final request is no longer open to people who never took part');
        self::assertNull($this->service()->visibleTo($this->propertyA(), '01arz3ndektsv4rrffq69g5fzz', $this->id($this->maker)));
    }

    // ------------------------------------------------------------------ evidence integrity

    public function test_approval_evidence_cannot_be_deleted_or_rewritten(): void
    {
        $this->policy();
        $id = $this->open();
        $this->service()->approve($this->propertyA(), $id, $this->id($this->supervisor));

        foreach ([
            fn () => DB::table('approval_requests')->delete(),
            fn () => DB::table('approval_decisions')->delete(),
            fn () => DB::table('approval_decisions')->update(['decision' => 'reject', 'reason' => 'x']),
            fn () => DB::table('approval_requests')->update(['status' => 'pending']),
            fn () => DB::table('approval_requests')->update(['status' => 'rejected', 'completed_at' => null]),
            fn () => DB::table('approval_decisions')->insert([
                'id' => '01arz3ndektsv4rrffq69g5fx1', 'property_id' => self::A, 'request_id' => $id, 'step' => 0,
                'approver_id' => $this->id($this->supervisor), 'decision' => 'approve', 'reason' => null,
                'decided_at' => now(), 'correlation_id' => '01arz3ndektsv4rrffq69g5fc1',
            ]),
        ] as $tamper) {
            try {
                $tamper();
                self::fail('Evidence must be protected by the database.');
            } catch (Throwable $e) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame('approved', DB::table('approval_requests')->value('status'));
        self::assertSame(1, DB::table('approval_decisions')->count());
    }
}
