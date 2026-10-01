<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The contract other modules use for maker-checker approval (NFR-06, BR-004).
 * The identity module owns the implementation; a module never touches approval
 * tables. Flow: `requirementFor` (is approval needed?) -> `request` -> people
 * decide -> the module `consume`s the approved request to perform the action.
 */
interface ApprovalGate
{
    /**
     * @throws MissingApprovalPolicy when the subject is declared mandatory and no policy applies:
     *                               the action fails closed instead of silently skipping approval
     * @throws UnknownApprovalSubject when the subject type was never declared in config/approvals.php
     */
    public function requirementFor(PropertyId $property, string $subjectType, ?int $amountMinor = null): ApprovalRequirement;

    /** Opens a request under the property's current policy. Repeating the call with the same key returns the same request. */
    public function request(ApprovalRequestInput $input, IdempotencyKey $key): ApprovalView;

    /**
     * Uses an approved request to perform the action, exactly once. The actor must
     * be the maker, the subject and payload must be identical to what was
     * approved, and the request must be approved and unused. Pass the request ID
     * as the approval reference of your own audit entry.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ApprovalNotUsable
     */
    public function consume(PropertyId $property, string $requestId, string $subjectType, string $subjectRef, array $payload, string $actorId): ApprovalView;

    public function find(PropertyId $property, string $requestId): ?ApprovalView;
}
