<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\IdentityAccess\Approval;

use App\Modules\IdentityAccess\Domain\Approval\ApprovalDecision;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalPolicy;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalRequest;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalRuleViolation;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStatus;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStep;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApprovalRequestTest extends TestCase
{
    private const MAKER = '01arz3ndektsv4rrffq69g5fc1';

    private const SUP = '01arz3ndektsv4rrffq69g5fc2';

    private const MGR = '01arz3ndektsv4rrffq69g5fc3';

    private const MGR2 = '01arz3ndektsv4rrffq69g5fc4';

    private function at(string $time = '2026-10-01 10:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    /** @param list<ApprovalStep> $steps */
    private function request(array $steps = [], string $hash = 'h'): ApprovalRequest
    {
        return new ApprovalRequest(
            '01arz3ndektsv4rrffq69g5fa1', '01arz3ndektsv4rrffq69g5fav', 'fnb.bill.void', 'bill-77', self::MAKER,
            'Guest complaint', $hash, ['bill' => 'bill-77'], null, 150000, 'IDR', null, null,
            '01arz3ndektsv4rrffq69g5fb1', $steps ?: [new ApprovalStep('fnb.void.approve')], null, $this->at(),
        );
    }

    private function violation(callable $act): string
    {
        try {
            $act();
        } catch (ApprovalRuleViolation $violation) {
            return $violation->reasonCode;
        }

        self::fail('A rule violation was expected.');
    }

    public function test_a_single_step_request_is_approved_by_one_other_person(): void
    {
        $request = $this->request();

        self::assertSame('fnb.void.approve', $request->currentStepPermission());
        $request->approve(self::SUP, $this->at());

        self::assertSame(ApprovalStatus::Approved, $request->status());
        self::assertNull($request->currentStepPermission());
        self::assertNotNull($request->completedAt());
        self::assertCount(1, $request->newDecisions());
    }

    public function test_the_maker_can_never_approve_or_reject_their_own_request_in_any_notation(): void
    {
        foreach ([self::MAKER, strtoupper(self::MAKER)] as $maker) {
            $request = $this->request();

            self::assertSame(ApprovalRuleViolation::SELF_APPROVAL, $this->violation(fn () => $request->approve($maker, $this->at())));
            self::assertSame(ApprovalRuleViolation::SELF_APPROVAL, $this->violation(fn () => $request->reject($maker, 'no', $this->at())));
            self::assertSame(ApprovalStatus::Pending, $request->status());
            self::assertSame([], $request->newDecisions());
        }
    }

    public function test_a_chain_advances_step_by_step_and_needs_the_required_number_of_distinct_people(): void
    {
        $request = $this->request([new ApprovalStep('fnb.void.approve', 1), new ApprovalStep('finance.refund.approve', 2)]);

        $request->approve(self::SUP, $this->at());
        self::assertSame(ApprovalStatus::Pending, $request->status());
        self::assertSame(1, $request->currentStep());
        self::assertSame('finance.refund.approve', $request->currentStepPermission());

        $request->approve(self::MGR, $this->at());
        self::assertSame(ApprovalStatus::Pending, $request->status(), 'the second step still needs a second person');

        $request->approve(self::MGR2, $this->at());
        self::assertSame(ApprovalStatus::Approved, $request->status());
        self::assertCount(3, $request->decisions());
    }

    public function test_one_person_can_never_supply_two_approvals_even_across_steps(): void
    {
        $request = $this->request([new ApprovalStep('fnb.void.approve'), new ApprovalStep('finance.refund.approve')]);
        $request->approve(self::SUP, $this->at());

        // The same person holds the second step's permission too, but cannot approve again.
        self::assertSame(ApprovalRuleViolation::ALREADY_DECIDED, $this->violation(fn () => $request->approve(self::SUP, $this->at())));
        self::assertSame(ApprovalStatus::Pending, $request->status());
    }

    public function test_a_rejection_needs_a_reason_and_ends_the_request(): void
    {
        $request = $this->request([new ApprovalStep('fnb.void.approve'), new ApprovalStep('finance.refund.approve')]);

        foreach (['', '   ', str_repeat('x', 501)] as $reason) {
            self::assertSame(ApprovalRuleViolation::REASON_REQUIRED, $this->violation(fn () => $request->reject(self::SUP, $reason, $this->at())));
        }

        $request->reject(self::SUP, '  Not justified  ', $this->at());

        self::assertSame(ApprovalStatus::Rejected, $request->status());
        self::assertSame('Not justified', $request->decisions()[0]->reason);
        self::assertSame(ApprovalDecision::REJECT, $request->decisions()[0]->decision);
    }

    public function test_nothing_can_change_a_final_request(): void
    {
        foreach ([
            'approved' => function (): ApprovalRequest {
                $r = $this->request();
                $r->approve(self::SUP, $this->at());

                return $r;
            },
            'rejected' => function (): ApprovalRequest {
                $r = $this->request();
                $r->reject(self::SUP, 'no', $this->at());

                return $r;
            },
            'cancelled' => function (): ApprovalRequest {
                $r = $this->request();
                $r->cancel(self::MAKER, $this->at());

                return $r;
            },
        ] as $label => $make) {
            $request = $make();

            self::assertSame(ApprovalRuleViolation::NOT_PENDING, $this->violation(fn () => $request->approve(self::MGR, $this->at())), $label);
            self::assertSame(ApprovalRuleViolation::NOT_PENDING, $this->violation(fn () => $request->reject(self::MGR, 'x', $this->at())), $label);
            self::assertSame(ApprovalRuleViolation::NOT_PENDING, $this->violation(fn () => $request->cancel(self::MAKER, $this->at())), $label);
        }
    }

    public function test_only_the_maker_can_cancel_and_only_while_pending(): void
    {
        $request = $this->request();

        self::assertSame(ApprovalRuleViolation::NOT_MAKER, $this->violation(fn () => $request->cancel(self::SUP, $this->at())));
        $request->cancel(strtoupper(self::MAKER), $this->at());
        self::assertSame(ApprovalStatus::Cancelled, $request->status());
    }

    public function test_an_approval_is_used_once_by_the_maker_for_exactly_what_was_approved(): void
    {
        $request = $this->request(hash: 'abc123');
        $consume = fn (string $actor = self::MAKER, string $type = 'fnb.bill.void', string $ref = 'bill-77', string $hash = 'abc123') => $request->consume($actor, $type, $ref, $hash, $this->at());

        self::assertSame(ApprovalRuleViolation::NOT_APPROVED, $this->violation($consume), 'a pending request cannot be used');

        $request->approve(self::SUP, $this->at());

        self::assertSame(ApprovalRuleViolation::NOT_MAKER, $this->violation(fn () => $consume(self::SUP)), 'not even the approver may execute it');
        self::assertSame(ApprovalRuleViolation::SUBJECT_MISMATCH, $this->violation(fn () => $consume(type: 'fnb.bill.discount')));
        self::assertSame(ApprovalRuleViolation::SUBJECT_MISMATCH, $this->violation(fn () => $consume(ref: 'bill-78')));
        self::assertSame(ApprovalRuleViolation::PAYLOAD_MISMATCH, $this->violation(fn () => $consume(hash: 'abc124')), 'approve 10%, apply 100% is refused');
        self::assertNull($request->consumedAt(), 'refused attempts consume nothing');

        $consume();
        self::assertNotNull($request->consumedAt());
        self::assertSame(ApprovalRuleViolation::ALREADY_CONSUMED, $this->violation($consume), 'single use');
    }

    public function test_a_rejected_or_cancelled_request_cannot_be_used(): void
    {
        $rejected = $this->request();
        $rejected->reject(self::SUP, 'no', $this->at());

        self::assertSame(ApprovalRuleViolation::NOT_APPROVED, $this->violation(fn () => $rejected->consume(self::MAKER, 'fnb.bill.void', 'bill-77', 'h', $this->at())));
    }

    public function test_a_superseded_request_closes_as_cancelled_without_the_makers_act(): void
    {
        $request = $this->request();
        $request->supersede($this->at());

        self::assertSame(ApprovalStatus::Cancelled, $request->status());
    }

    public function test_policy_and_step_validate_their_configuration(): void
    {
        foreach ([
            fn () => new ApprovalStep('NotAPermission'),
            fn () => new ApprovalStep('fnb.void.approve', 0),
            fn () => new ApprovalStep('fnb.void.approve', 6),
            fn () => new ApprovalPolicy('id', 'fnb.bill.void', -1, [new ApprovalStep('a.b')]),
            fn () => new ApprovalPolicy('id', 'fnb.bill.void', 0, []),
            fn () => new ApprovalPolicy('id', 'fnb.bill.void', 0, array_fill(0, 6, new ApprovalStep('a.b'))),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid configuration must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $policy = new ApprovalPolicy('id', 'fnb.bill.void', 100000, [new ApprovalStep('a.b', 1), new ApprovalStep('c.d', 2)]);
        self::assertSame(3, $policy->totalApprovals());
    }

    public function test_evidence_summarizes_state_without_personal_data(): void
    {
        $request = $this->request();
        $request->approve(self::SUP, $this->at());

        self::assertSame(['status' => 'approved', 'current_step' => 0, 'decisions' => 1, 'consumed' => false], $request->evidence());
    }
}
