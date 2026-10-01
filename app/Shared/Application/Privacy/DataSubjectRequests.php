<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use Closure;
use DateTimeImmutable;

/**
 * Register of data subject requests with the response deadline (NFR-08, UU 27/2022). It records who asked, how
 * the identity was verified, when it is due, and the decision with its legal basis. Carrying out the request (export
 * the data, correct a field, anonymize) belongs to the module that owns the data and is recorded here as the note.
 * `$dueHours` maps a request type to its internal response target in hours.
 */
final readonly class DataSubjectRequests
{
    public const MANAGE_PERMISSION = 'privacy.request.manage';

    /** @param array<string, int> $dueHours */
    public function __construct(
        private DataSubjectRequestRepository $requests,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
        private array $dueHours,
    ) {}

    public function open(PropertyId $property, string $actorId, string $subjectType, string $subjectId, string $type, string $channel, string $verificationNote): DataSubjectRequest
    {
        $this->authorize($property, $actorId);

        if (! in_array($type, DataSubjectRequest::TYPES, true) || ! isset($this->dueHours[$type])) {
            throw PrivacyRefused::invalid('Unknown request type.', ['request_type']);
        }

        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $subjectType) !== 1 || preg_match('/^[0-9a-z]{26}$/', $subjectId) !== 1) {
            throw PrivacyRefused::invalid('A request needs the person it is about.', ['subject']);
        }

        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $channel) !== 1 || trim($verificationNote) === '' || mb_strlen($verificationNote) > 500) {
            throw PrivacyRefused::invalid('A request records the channel and how the person was identified (at most 500 characters).', ['channel', 'verification_note']);
        }

        $now = $this->clock->nowUtc();
        $request = new DataSubjectRequest(
            $this->ids->next(), $subjectType, strtolower($subjectId), $type, DataSubjectRequest::RECEIVED, $channel, trim($verificationNote),
            $now, $now->modify('+'.$this->dueHours[$type].' hours'), null, null, null, null, 0, strtolower($actorId),
        );

        $this->transactions->run(function () use ($property, $request): void {
            $this->requests->add($property, $request);
            $this->audit->record(new AuditEntry($property->toString(), $request->createdBy, 'privacy.request.received', 'data_subject_request', $request->id, null, $this->describe($request)));
        });

        return $request;
    }

    public function start(PropertyId $property, string $actorId, string $id): DataSubjectRequest
    {
        return $this->transition($property, $actorId, $id, 'privacy.request.started', null, static fn (DataSubjectRequest $r, DateTimeImmutable $now): DataSubjectRequest => $r->start($actorId));
    }

    public function complete(PropertyId $property, string $actorId, string $id, string $note): DataSubjectRequest
    {
        return $this->transition($property, $actorId, $id, 'privacy.request.completed', $note, static fn (DataSubjectRequest $r, DateTimeImmutable $now): DataSubjectRequest => $r->complete($actorId, $note, $now));
    }

    public function refuse(PropertyId $property, string $actorId, string $id, string $basis, string $note): DataSubjectRequest
    {
        return $this->transition($property, $actorId, $id, 'privacy.request.refused', $basis, static fn (DataSubjectRequest $r, DateTimeImmutable $now): DataSubjectRequest => $r->refuse($actorId, $basis, $note, $now));
    }

    /** @return list<DataSubjectRequest> */
    public function openRequests(PropertyId $property, string $actorId, int $limit = 100): array
    {
        $this->authorize($property, $actorId);

        return $this->requests->open($property, max(1, min($limit, 500)));
    }

    /** @param Closure(DataSubjectRequest, DateTimeImmutable): DataSubjectRequest $change */
    private function transition(PropertyId $property, string $actorId, string $id, string $action, ?string $reason, Closure $change): DataSubjectRequest
    {
        $this->authorize($property, $actorId);

        $before = $this->requests->find($property, strtolower($id)) ?? throw PrivacyRefused::notFound('Request not found.');
        $after = $change($before, $this->clock->nowUtc());

        $this->transactions->run(function () use ($property, $before, $after, $actorId, $action, $reason): void {
            if (! $this->requests->save($property, $after, $before->lockVersion)) {
                throw PrivacyRefused::stateConflict('This request was changed by someone else. Refresh and review it.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), strtolower($actorId), $action, 'data_subject_request', $after->id,
                $this->describe($before), $this->describe($after), $reason === null ? null : mb_substr(trim($reason), 0, 500),
            ));
        });

        return new DataSubjectRequest(
            $after->id, $after->subjectType, $after->subjectId, $after->type, $after->status, $after->channel, $after->verificationNote,
            $after->receivedAt, $after->dueAt, $after->decisionBasis, $after->decisionNote, $after->handledBy, $after->completedAt, $before->lockVersion + 1, $after->createdBy,
        );
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw PrivacyRefused::forbidden('This person may not handle privacy requests.');
        }
    }

    /** @return array<string, mixed> */
    private function describe(DataSubjectRequest $request): array
    {
        return array_filter([
            'subject_type' => $request->subjectType,
            'subject_id' => $request->subjectId,
            'request_type' => $request->type,
            'status' => $request->status,
            'due_at' => $request->dueAt->format('Y-m-d\TH:i:s\Z'),
            'decision_basis' => $request->decisionBasis,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
