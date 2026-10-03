<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The screen where a manager reconciles what a phone or a register recorded offline and the server could not apply (NFR-04, NFR-30). Every conflict or refusal is kept as it came, and nothing is dropped:
 * a person looks at what it was (the kind of operation, who made it, on which device, why it was not applied), does what the business needs in the module itself (re-enter the sale, give the room), and
 * marks it reconciled with a note. The details of the payload are not shown here, because they may hold business data; the person who made the entry is asked, and cannot close their own.
 */
final readonly class SyncExceptionService
{
    public const RECONCILE_PERMISSION = 'offline.reconcile';

    public function __construct(
        private SyncExceptionRepository $exceptions,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, open: int, may: array{reconcile: bool}}
     */
    public function overview(PropertyId $property, string $actorId, string $status): array
    {
        $this->authorize($property, $actorId);

        if (! in_array($status, ['open', 'resolved', 'all'], true)) {
            throw Refusal::invalid('Choose open, resolved or all.', ['status']);
        }

        $rows = $this->exceptions->forReview($property, $status === 'all' ? null : $status, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_merge(array_column($rows, 'actor_id'), array_filter(array_column($rows, 'resolved_by'))))));

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'operation_type' => $r['operation_type'], 'kind' => $r['kind'], 'reason_code' => $r['reason_code'], 'conflict_action' => $r['conflict_action'], 'device' => substr((string) $r['device_id'], -6),
                'actor' => $names[$r['actor_id']] ?? null, 'actor_id' => $r['actor_id'], 'received_at' => $r['received_at'], 'status' => $r['status'], 'resolved_at' => $r['resolved_at'], 'resolved_by' => $r['resolved_by'] === null ? null : ($names[$r['resolved_by']] ?? null),
                'resolution_note' => $r['resolution_note'], 'lock_version' => (int) $r['lock_version'],
            ], $rows),
            'open' => $this->exceptions->openCount($property),
            'may' => ['reconcile' => true],
        ];
    }

    /** Marks one reconciled, with what was done about it. */
    public function resolve(PropertyId $property, string $actorId, string $id, int $lock, string $note): void
    {
        $this->authorize($property, $actorId);
        $note = trim($note);

        if ($note === '' || mb_strlen($note) > 500) {
            throw Refusal::invalid('Say what was done, in at most 500 characters.', ['note']);
        }

        $row = $this->exceptions->find($property, strtolower($id)) ?? throw Refusal::notFound('Sync problem not found.');

        if ($row['status'] !== 'open') {
            throw Refusal::stateConflict('This was reconciled already.');
        }

        if (strtolower((string) $row['actor_id']) === strtolower($actorId)) {
            throw Refusal::stateConflict('The person who made the entry asks someone else to reconcile it.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $row, $lock, $note): void {
            if (! $this->exceptions->resolve($property, (string) $row['id'], $lock, $actor, $note, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'offline.exception.resolved', 'offline_sync_exception', (string) $row['id'], ['status' => 'open'], ['status' => 'resolved', 'operation_type' => $row['operation_type'], 'reason_code' => $row['reason_code']], $note));
        });
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::RECONCILE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not reconcile offline entries.');
        }
    }
}
