<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalDecision;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalRequest;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalRuleViolation;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStatus;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStep;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalNotUsable;
use App\Shared\Application\Approval\ApprovalPayloadHash;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\ApprovalRequirement;
use App\Shared\Application\Approval\ApprovalSubjects;
use App\Shared\Application\Approval\ApprovalView;
use App\Shared\Application\Approval\MissingApprovalPolicy;
use App\Shared\Application\Approval\UnknownApprovalSubject;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Maker-checker approval (NFR-06, BR-004). The identity context owns it; other
 * modules reach it only through `ApprovalGate`.
 *
 * Everything runs in the caller's property context and in one transaction with
 * its audit entry and outbox event, so a decision is never recorded without its
 * evidence. Concurrent decisions cannot overwrite each other: the request is
 * compare-and-swapped on its lock version and the loser gets a conflict (NFR-19).
 */
final readonly class ApprovalService implements ApprovalGate
{
    public function __construct(
        private ApprovalRepository $requests,
        private ApprovalPolicyRepository $policies,
        private ApprovalSubjects $subjects,
        private PermissionChecker $permissions,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private SecurityLog $securityLog,
        private Clock $clock,
        private CorrelationId $correlation,
        private PropertyContext $property,
        private IdentifierGenerator $ids,
    ) {}

    public function requirementFor(PropertyId $property, string $subjectType, ?int $amountMinor = null): ApprovalRequirement
    {
        $this->assertProperty($property);
        $this->assertDeclared($subjectType);

        $policy = $this->policies->resolve($property, $subjectType, $amountMinor);

        if ($policy !== null) {
            return ApprovalRequirement::required($policy->id);
        }

        if ($this->subjects->isMandatory($subjectType)) {
            throw MissingApprovalPolicy::for($subjectType);
        }

        return ApprovalRequirement::notRequired();
    }

    public function request(ApprovalRequestInput $input, IdempotencyKey $key): ApprovalView
    {
        $this->assertProperty($input->propertyId);
        $this->assertDeclared($input->subjectType);

        try {
            $result = $this->executor->execute(
                new IdempotencyRequest($input->propertyId, $key, 'approval.request', [
                    'subject_type' => $input->subjectType,
                    'subject_ref' => $input->subjectRef,
                    'payload_hash' => $input->payloadHash(),
                    'reason' => $input->reason,
                    'amount_minor' => $input->amountMinor,
                    'scope' => [$input->scopeType, $input->scopeId],
                    'supersedes' => $input->supersedes,
                ], $input->makerId),
                fn (): array => ['id' => $this->open($input)],
            );
        } catch (ApprovalRuleViolation $violation) {
            throw ApprovalRefused::from($violation);
        }

        return $this->view($this->load($input->propertyId, (string) $result->payload['id']));
    }

    /** Records one approval. Returns the request; repeating the same decision is a no-op. */
    public function approve(PropertyId $property, string $requestId, string $approverId): ApprovalView
    {
        return $this->decide($property, $requestId, $approverId, ApprovalDecision::APPROVE, null);
    }

    public function reject(PropertyId $property, string $requestId, string $approverId, string $reason): ApprovalView
    {
        return $this->decide($property, $requestId, $approverId, ApprovalDecision::REJECT, $reason);
    }

    public function cancel(PropertyId $property, string $requestId, string $actorId): ApprovalView
    {
        $this->assertProperty($property);

        try {
            return $this->transactions->run(function () use ($property, $requestId, $actorId): ApprovalView {
                $request = $this->load($property, $requestId);
                $before = $request->evidence();

                $request->cancel($actorId, $this->clock->nowUtc());

                $this->requests->save($request, $this->correlation->current());
                $this->record('approval.cancelled', $request, $actorId, $before, null);
                $this->announce($request);

                return $this->view($request);
            });
        } catch (ApprovalRuleViolation $violation) {
            // Recorded after the rollback, so the refused attempt is kept as evidence.
            $this->denyAttempt($property, $actorId, $requestId, $violation->reasonCode);

            throw ApprovalRefused::from($violation);
        }
    }

    public function consume(PropertyId $property, string $requestId, string $subjectType, string $subjectRef, array $payload, string $actorId): ApprovalView
    {
        $this->assertProperty($property);

        $outcome = $this->transactions->run(function () use ($property, $requestId, $subjectType, $subjectRef, $payload, $actorId): ApprovalView|ApprovalNotUsable {
            try {
                $request = $this->load($property, $requestId);
            } catch (ApprovalRequestNotFound) {
                return new ApprovalNotUsable('not_found', 'No such approval.');
            }

            $before = $request->evidence();

            try {
                $request->consume($actorId, $subjectType, $subjectRef, ApprovalPayloadHash::of($payload), $this->clock->nowUtc());
            } catch (ApprovalRuleViolation $violation) {
                // Recorded outside the rolled-back work: the attempt itself is evidence.
                $this->deny($property, $actorId, $request, $violation->reasonCode);

                return new ApprovalNotUsable($violation->reasonCode, $violation->getMessage());
            }

            $this->requests->save($request, $this->correlation->current());
            $this->record('approval.consumed', $request, $actorId, $before, null);

            return $this->view($request);
        });

        if ($outcome instanceof ApprovalNotUsable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function find(PropertyId $property, string $requestId): ?ApprovalView
    {
        $this->assertProperty($property);

        return $this->requests->find($property, $requestId) === null ? null : $this->view($this->load($property, $requestId));
    }

    /**
     * Requests this person may decide now: pending, not their own, not already decided by them,
     * and for which they hold the current step's permission (at the request's resource scope, if any).
     *
     * @return list<ApprovalView>
     */
    public function pendingFor(PropertyId $property, string $userId, int $limit = 100): array
    {
        $this->assertProperty($property);
        $cache = [];
        $eligible = [];

        foreach ($this->requests->pending($property, 500) as $request) {
            if (strtolower($userId) === $request->makerId || $request->decisionBy($userId) !== null) {
                continue;
            }

            $permission = (string) $request->currentStepPermission();
            $cacheKey = $permission.'|'.$request->scopeType.'|'.$request->scopeId;
            $cache[$cacheKey] ??= $this->holds($userId, $permission, $property, $request);

            if ($cache[$cacheKey]) {
                $eligible[] = $this->view($request);
            }

            if (count($eligible) >= $limit) {
                break;
            }
        }

        return $eligible;
    }

    /** @return list<ApprovalView> the person's own requests, newest first */
    public function requestedBy(PropertyId $property, string $userId, int $limit = 100): array
    {
        $this->assertProperty($property);

        return array_map($this->view(...), $this->requests->byMaker($property, $userId, $limit));
    }

    /** A request may be read by its maker, by someone who decided on it, or by anyone who could decide it now. */
    public function visibleTo(PropertyId $property, string $requestId, string $userId): ?ApprovalView
    {
        $this->assertProperty($property);

        try {
            $request = $this->load($property, $requestId);
        } catch (ApprovalRequestNotFound) {
            return null;
        }

        $involved = strtolower($userId) === $request->makerId || $request->decisionBy($userId) !== null;
        $could = $request->status() === ApprovalStatus::Pending
            && $this->holds($userId, (string) $request->currentStepPermission(), $property, $request);

        return $involved || $could ? $this->view($request) : null;
    }

    // ------------------------------------------------------------------ internals

    private function decide(PropertyId $property, string $requestId, string $approverId, string $decision, ?string $reason): ApprovalView
    {
        $this->assertProperty($property);

        try {
            return $this->transactions->run(function () use ($property, $requestId, $approverId, $decision, $reason): ApprovalView {
                $request = $this->load($property, $requestId);
                $prior = $request->decisionBy($approverId);

                // The same person repeating the same decision (a retry) changes nothing.
                if ($prior !== null && $prior->decision === $decision) {
                    return $this->view($request);
                }

                $before = $request->evidence();
                $request->assertDecidableBy($approverId);

                if (! $this->holds($approverId, (string) $request->currentStepPermission(), $property, $request)) {
                    throw new NotAnEligibleApprover('This person does not hold the permission this step requires.');
                }

                $decision === ApprovalDecision::APPROVE
                    ? $request->approve($approverId, $this->clock->nowUtc())
                    : $request->reject($approverId, (string) $reason, $this->clock->nowUtc());

                $this->requests->save($request, $this->correlation->current());
                $this->record(
                    $decision === ApprovalDecision::APPROVE ? 'approval.approved' : 'approval.rejected',
                    $request,
                    $approverId,
                    $before,
                    $reason,
                );

                if ($request->status()->isFinal()) {
                    $this->announce($request);
                }

                return $this->view($request);
            });
        } catch (ApprovalRuleViolation $violation) {
            $this->denyAttempt($property, $approverId, $requestId, $violation->reasonCode);

            throw ApprovalRefused::from($violation);
        } catch (NotAnEligibleApprover $refused) {
            $this->denyAttempt($property, $approverId, $requestId, 'not_an_eligible_approver');

            throw $refused;
        }
    }

    private function open(ApprovalRequestInput $input): string
    {
        $policy = $this->policies->resolve($input->propertyId, $input->subjectType, $input->amountMinor)
            ?? throw MissingApprovalPolicy::for($input->subjectType);

        $now = $this->clock->nowUtc();

        if ($input->supersedes !== null) {
            $old = $this->load($input->propertyId, $input->supersedes);

            if ($old->subjectType !== $input->subjectType || $old->subjectRef !== $input->subjectRef || $old->makerId !== strtolower($input->makerId)) {
                throw new ApprovalRuleViolation(ApprovalRuleViolation::SUBJECT_MISMATCH, 'A request can only supersede the same maker\'s request for the same subject.');
            }

            $before = $old->evidence();
            $old->supersede($now);
            $this->requests->save($old, $this->correlation->current());
            $this->record('approval.superseded', $old, $input->makerId, $before, 'Replaced by a new request.');
            $this->announce($old);
        }

        $request = new ApprovalRequest(
            $this->ids->next(),
            $input->propertyId->toString(),
            $input->subjectType,
            $input->subjectRef,
            strtolower($input->makerId),
            trim($input->reason),
            $input->payloadHash(),
            $input->payload,
            $input->before,
            $input->amountMinor,
            $input->currency,
            $input->scopeType,
            $input->scopeId === null ? null : strtolower($input->scopeId),
            $policy->id,
            $policy->steps,
            $input->supersedes === null ? null : strtolower($input->supersedes),
            $now,
        );

        $this->requests->add($request, $this->correlation->current());
        $this->audit->record(new AuditEntry(
            $input->propertyId->toString(),
            strtolower($input->makerId),
            'approval.requested',
            'approval_request',
            $request->id,
            null,
            $request->evidence() + ['subject_type' => $input->subjectType, 'subject_ref' => $input->subjectRef, 'payload_hash' => $request->payloadHash, 'amount_minor' => $input->amountMinor, 'steps' => count($policy->steps)],
            $request->reason,
            $request->id,
        ));

        return $request->id;
    }

    private function holds(string $userId, string $permission, PropertyId $property, ApprovalRequest $request): bool
    {
        return $request->scopeType === null
            ? $this->permissions->allowsInProperty($userId, $permission, $property)
            : $this->permissions->allowsInScope($userId, $permission, $property, $request->scopeType, (string) $request->scopeId);
    }

    /** @param array<string, mixed> $before */
    private function record(string $action, ApprovalRequest $request, string $actorId, array $before, ?string $reason): void
    {
        $this->audit->record(new AuditEntry(
            $request->propertyId,
            strtolower($actorId),
            $action,
            'approval_request',
            $request->id,
            $before,
            $request->evidence(),
            $reason,
            $request->id,
        ));
    }

    /** A final outcome other modules may react to, published in the same transaction as the decision. */
    private function announce(ApprovalRequest $request): void
    {
        $this->outbox->publish(new OutboxEvent(
            PropertyId::fromString($request->propertyId),
            'identity.approval.decided',
            $request->id,
            1,
            [
                'request_id' => $request->id,
                'subject_type' => $request->subjectType,
                'subject_ref' => $request->subjectRef,
                'status' => $request->status()->value,
            ],
        ));
    }

    private function deny(PropertyId $property, string $actorId, ApprovalRequest $request, string $reason): void
    {
        $this->securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::ApprovalDenied->value,
            SecurityEventOutcome::Denied,
            strtolower($actorId),
            $property->toString(),
            ['request_id' => $request->id, 'subject_type' => $request->subjectType, 'reason_code' => $reason],
        ));
    }

    /** A refused attempt, recorded outside the transaction that was rolled back. */
    private function denyAttempt(PropertyId $property, string $actorId, string $requestId, string $reason): void
    {
        $this->securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::ApprovalDenied->value,
            SecurityEventOutcome::Denied,
            strtolower($actorId),
            $property->toString(),
            ['request_id' => strtolower($requestId), 'reason_code' => $reason],
        ));
    }

    private function load(PropertyId $property, string $id): ApprovalRequest
    {
        return $this->requests->find($property, $id) ?? throw new ApprovalRequestNotFound('The approval request was not found.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }

    private function assertDeclared(string $subjectType): void
    {
        if (! $this->subjects->isDeclared($subjectType)) {
            throw UnknownApprovalSubject::for($subjectType);
        }
    }

    private function view(ApprovalRequest $request): ApprovalView
    {
        return new ApprovalView(
            $request->id,
            $request->propertyId,
            $request->subjectType,
            $request->subjectRef,
            $request->makerId,
            $request->reason,
            $request->status()->value,
            $request->currentStep(),
            array_map(static fn (ApprovalStep $step): array => $step->toArray(), $request->steps),
            array_map(static fn (ApprovalDecision $d): array => [
                'step' => $d->step,
                'approver_id' => $d->approverId,
                'decision' => $d->decision,
                'reason' => $d->reason,
                'decided_at' => $d->decidedAt->format('Y-m-d\TH:i:s.u\Z'),
            ], $request->decisions()),
            $request->payload,
            $request->before,
            $request->amountMinor,
            $request->currency,
            $request->createdAt,
            $request->completedAt(),
            $request->consumedAt() !== null,
        );
    }
}
