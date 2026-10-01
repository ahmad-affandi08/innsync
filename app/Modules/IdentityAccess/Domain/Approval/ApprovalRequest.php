<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Approval;

use DateTimeImmutable;

/**
 * A request for approval and its state machine (NFR-06, BR-004, BR-011).
 *
 * Hard rules, independent of configuration:
 *  - the maker can never approve or reject their own request (no self-approval);
 *  - each person decides at most once, so a chain always involves different people;
 *  - a rejection ends the request; an approval advances it step by step;
 *  - a final request never changes again, except that an approved one is consumed once;
 *  - an approval can be used only by its maker, only for the exact subject and
 *    payload that was approved, and only once.
 *
 * Who is *allowed* to approve (a permission at the property or resource) is checked
 * by the application layer before calling `approve` or `reject`.
 */
final class ApprovalRequest
{
    /** @var list<ApprovalDecision> */
    private array $decisions;

    /** @var list<ApprovalDecision> decisions added since it was loaded */
    private array $newDecisions = [];

    /**
     * @param  list<ApprovalStep>  $steps  snapshot of the policy this request was opened under
     * @param  list<ApprovalDecision>  $decisions
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $before
     */
    public function __construct(
        public readonly string $id,
        public readonly string $propertyId,
        public readonly string $subjectType,
        public readonly string $subjectRef,
        public readonly string $makerId,
        public readonly string $reason,
        public readonly string $payloadHash,
        public readonly array $payload,
        public readonly ?array $before,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $scopeType,
        public readonly ?string $scopeId,
        public readonly string $policyId,
        public readonly array $steps,
        public readonly ?string $supersedesId,
        public readonly DateTimeImmutable $createdAt,
        private ApprovalStatus $status = ApprovalStatus::Pending,
        private int $currentStep = 0,
        array $decisions = [],
        private ?DateTimeImmutable $completedAt = null,
        private ?DateTimeImmutable $consumedAt = null,
        private int $lockVersion = 0,
    ) {
        $this->decisions = $decisions;
    }

    public function status(): ApprovalStatus
    {
        return $this->status;
    }

    public function currentStep(): int
    {
        return $this->currentStep;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function consumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /** @return list<ApprovalDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /** @return list<ApprovalDecision> */
    public function newDecisions(): array
    {
        return $this->newDecisions;
    }

    /** The permission an approver needs for the step now waiting, or null once the request is final. */
    public function currentStepPermission(): ?string
    {
        return $this->status === ApprovalStatus::Pending ? $this->steps[$this->currentStep]->permission : null;
    }

    public function decisionBy(string $userId): ?ApprovalDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->approverId === strtolower($userId)) {
                return $decision;
            }
        }

        return null;
    }

    public function approve(string $approverId, DateTimeImmutable $at): void
    {
        $this->assertDecidableBy($approverId);

        $this->record(new ApprovalDecision($this->currentStep, strtolower($approverId), ApprovalDecision::APPROVE, null, $at));

        $approvalsAtStep = count(array_filter(
            $this->decisions,
            fn (ApprovalDecision $d): bool => $d->step === $this->currentStep && $d->decision === ApprovalDecision::APPROVE,
        ));

        if ($approvalsAtStep < $this->steps[$this->currentStep]->approvalsRequired) {
            return;
        }

        if ($this->currentStep + 1 < count($this->steps)) {
            $this->currentStep++;

            return;
        }

        $this->status = ApprovalStatus::Approved;
        $this->completedAt = $at;
    }

    public function reject(string $approverId, string $reason, DateTimeImmutable $at): void
    {
        $this->assertDecidableBy($approverId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::REASON_REQUIRED, 'A rejection needs a reason of at most 500 characters.');
        }

        $this->record(new ApprovalDecision($this->currentStep, strtolower($approverId), ApprovalDecision::REJECT, $reason, $at));
        $this->status = ApprovalStatus::Rejected;
        $this->completedAt = $at;
    }

    /** Only the maker can withdraw, and only while nothing is final. */
    public function cancel(string $actorId, DateTimeImmutable $at): void
    {
        if ($this->status !== ApprovalStatus::Pending) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::NOT_PENDING, 'Only a pending request can be cancelled.');
        }

        if (strtolower($actorId) !== $this->makerId) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::NOT_MAKER, 'Only the maker can cancel a request.');
        }

        $this->status = ApprovalStatus::Cancelled;
        $this->completedAt = $at;
    }

    /** A request replaced by a newer one (a material change needs fresh approval) is closed without the maker's act. */
    public function supersede(DateTimeImmutable $at): void
    {
        if ($this->status !== ApprovalStatus::Pending) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::NOT_PENDING, 'Only a pending request can be superseded.');
        }

        $this->status = ApprovalStatus::Cancelled;
        $this->completedAt = $at;
    }

    /** Uses the approval to perform the action. Exactly once, by the maker, for exactly what was approved. */
    public function consume(string $actorId, string $subjectType, string $subjectRef, string $payloadHash, DateTimeImmutable $at): void
    {
        if ($this->status !== ApprovalStatus::Approved) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::NOT_APPROVED, 'The request is not approved.');
        }

        if ($this->consumedAt !== null) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::ALREADY_CONSUMED, 'This approval was already used.');
        }

        if (strtolower($actorId) !== $this->makerId) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::NOT_MAKER, 'Only the maker can use an approval.');
        }

        if ($subjectType !== $this->subjectType || $subjectRef !== $this->subjectRef) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::SUBJECT_MISMATCH, 'The approval was granted for a different subject.');
        }

        if (! hash_equals($this->payloadHash, $payloadHash)) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::PAYLOAD_MISMATCH, 'The change differs from what was approved.');
        }

        $this->consumedAt = $at;
    }

    /** @return array<string, mixed> state evidence for the audit trail */
    public function evidence(): array
    {
        return [
            'status' => $this->status->value,
            'current_step' => $this->currentStep,
            'decisions' => count($this->decisions),
            'consumed' => $this->consumedAt !== null,
        ];
    }

    /** Whether this person may still decide, by the request's own rules (before any permission check). */
    public function assertDecidableBy(string $approverId): void
    {
        if ($this->status !== ApprovalStatus::Pending) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::NOT_PENDING, 'The request is no longer pending.');
        }

        if (strtolower($approverId) === $this->makerId) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::SELF_APPROVAL, 'The maker cannot decide their own request.');
        }

        if ($this->decisionBy($approverId) !== null) {
            throw new ApprovalRuleViolation(ApprovalRuleViolation::ALREADY_DECIDED, 'This person already decided on the request.');
        }
    }

    private function record(ApprovalDecision $decision): void
    {
        $this->decisions[] = $decision;
        $this->newDecisions[] = $decision;
    }
}
