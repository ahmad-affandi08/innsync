<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Domain\Stays\GuestProfile;
use App\Modules\FrontOffice\Domain\Stays\IdType;
use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Modules\FrontOffice\Domain\Stays\StayRuleViolation;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\ApprovalRequired;
use App\Shared\Application\Approval\ApprovalView;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Application\Privacy\PiiAccessAudit;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Correcting what was recorded about a guest after check-in (FR-FO-039): a misspelled name, a wrong document number. The registration
 * is corrected in place and every changed field is kept as a fact with its value before and after (sealed, because they are personal
 * data), who and why. Correcting the identity (document type or number, nationality, visa) is critical: it needs the permission to see
 * identity, and when the property configured an approval policy `front-office.guest.correction`, an approved request bound to exactly
 * these new values. A correction of the name, validity date or address never needs approval. Room moves have their own audited flow
 * (`StayAmendmentService`).
 */
final readonly class GuestCorrectionService
{
    public const CORRECT_PERMISSION = 'front-office.guest.correct';

    public const SUBJECT = 'front-office.guest.correction';

    public const FIELDS = ['full_name', 'nationality', 'id_type', 'id_number', 'id_valid_until', 'visa_number', 'address'];

    /** Changing any of these is a change of who the guest is, or of the document that proves it. */
    public const CRITICAL = ['nationality', 'id_type', 'id_number', 'visa_number'];

    public function __construct(
        private StayRepository $stays,
        private GuestRepository $guests,
        private ApprovalGate $approvals,
        private FieldCipher $cipher,
        private PiiAccessAudit $piiAccess,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * What has been corrected on this stay's guest. Identity values are shown only to people who may read identity, and that is recorded.
     *
     * @return array{corrections: list<array<string, mixed>>, approvals: list<array<string, mixed>>, may_correct: bool, may_correct_identity: bool}
     */
    public function history(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->assertProperty($property);
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');
        $mayCorrect = $this->permissions->allowsInProperty($actorId, self::CORRECT_PERMISSION, $property);
        $mayIdentity = $this->permissions->allowsInProperty($actorId, StayService::IDENTITY_PERMISSION, $property);

        if (! $mayCorrect && ! $this->permissions->allowsInProperty($actorId, StayService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the corrections of a guest.');
        }

        $rows = $this->guests->corrections($property, $stay->id);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'created_by'))));
        $identityShown = false;

        $rows = array_map(function (array $r) use ($mayIdentity, $names, &$identityShown): array {
            $identity = in_array($r['field'], self::CRITICAL, true) || $r['field'] === 'address';
            $show = ! $identity || $mayIdentity;
            $identityShown = $identityShown || ($identity && $mayIdentity);

            return [
                'field' => $r['field'], 'old' => $show ? $r['old'] : ($r['old'] === null ? null : GuestProfile::mask($r['old'])), 'new' => $show ? $r['new'] : ($r['new'] === null ? null : GuestProfile::mask($r['new'])),
                'reason' => $r['reason'], 'approved' => $r['approval_id'] !== null, 'by' => $names[$r['created_by']] ?? null, 'at' => $r['created_at'],
            ];
        }, $rows);

        if ($identityShown) {
            $this->piiAccess->record($property, strtolower($actorId), 'stay', $stay->id, 'Viewed the corrections of the guest registration', ['id_number', 'visa_number', 'address']);
        }

        $approvals = [];

        foreach ($this->approvals->requestedBy($property, strtolower($actorId), 200) as $view) {
            if ($view->subjectType === self::SUBJECT && $view->subjectRef === $stay->id) {
                $approvals[] = ['id' => $view->id, 'status' => $view->status, 'consumed' => $view->consumed, 'fields' => $view->payload['fields'] ?? []];
            }
        }

        return ['corrections' => $rows, 'approvals' => $approvals, 'may_correct' => $mayCorrect, 'may_correct_identity' => $mayCorrect && $mayIdentity];
    }

    /**
     * Opens the approval a correction of the identity needs, bound to exactly these new values.
     *
     * @param  array<string, string|null>  $changes
     */
    public function requestApproval(PropertyId $property, string $actorId, string $stayId, array $changes, string $reason, IdempotencyKey $key): ApprovalView
    {
        $plan = $this->plan($property, $actorId, $stayId, $changes, $reason);

        if (array_intersect($plan['fields'], self::CRITICAL) === []) {
            throw Refusal::stateConflict('Only a correction of the identity needs approval.');
        }

        return $this->approvals->request(new ApprovalRequestInput(
            $property, self::SUBJECT, $plan['stay']->id, strtolower($actorId), $plan['reason'], $this->payload($plan), ['fields' => $plan['fields']],
        ), $key);
    }

    /**
     * @param  array<string, string|null>  $changes  field to its new value (a date as YYYY-MM-DD, an empty visa or date as null)
     * @return array<string, mixed> the history after the change
     */
    public function correct(PropertyId $property, string $actorId, string $stayId, array $changes, string $reason, ?string $approvalId): array
    {
        $plan = $this->plan($property, $actorId, $stayId, $changes, $reason);
        $actor = strtolower($actorId);
        $critical = array_values(array_intersect($plan['fields'], self::CRITICAL)) !== [];

        $this->transactions->run(function () use ($property, $actor, $plan, $critical, $approvalId): void {
            $approval = null;

            if ($critical && $this->approvals->requirementFor($property, self::SUBJECT)->required) {
                if ($approvalId === null || $approvalId === '') {
                    throw new ApprovalRequired;
                }

                $this->approvals->consume($property, strtolower($approvalId), self::SUBJECT, $plan['stay']->id, $this->payload($plan), $actor);
                $approval = strtolower($approvalId);
            }

            $now = $this->clock->nowUtc();
            $this->guests->replace($property, $plan['after'], $now);

            foreach ($plan['fields'] as $field) {
                $this->guests->addCorrection($property, [
                    'id' => $this->ids->next(), 'guest_id' => $plan['after']->id, 'stay_id' => $plan['stay']->id, 'field' => $field,
                    'old' => $plan['old'][$field], 'new' => $plan['new'][$field], 'reason' => $plan['reason'], 'approval_id' => $approval,
                ], $actor, $now);
            }

            // Only the names of the fields: the values are personal data and live sealed in the corrections.
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest.corrected', 'stay', $plan['stay']->id, ['fields' => $plan['fields']], ['fields' => $plan['fields'], 'critical' => $critical], $plan['reason'], $approval));
            $this->piiAccess->record($property, $actor, 'stay', $plan['stay']->id, 'Corrected the guest registration', $plan['fields']);
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.guest.corrected', $plan['stay']->id, 1, ['stay_id' => $plan['stay']->id, 'guest_id' => $plan['after']->id, 'fields' => $plan['fields'], 'actor_id' => $actor]));
        });

        return $this->history($property, $actorId, $stayId);
    }

    // ---- internals ----

    /**
     * @param  array<string, string|null>  $changes
     * @return array{stay: Stay, after: GuestProfile, fields: list<string>, old: array<string, ?string>, new: array<string, ?string>, reason: string, hash: string}
     */
    private function plan(PropertyId $property, string $actorId, string $stayId, array $changes, string $reason): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::CORRECT_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not correct guest details.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $unknown = array_diff(array_keys($changes), self::FIELDS);

        if ($unknown !== [] || $changes === []) {
            throw Refusal::invalid('Choose the fields to correct from the registration form.', ['changes']);
        }

        if (array_intersect(array_keys($changes), self::CRITICAL) !== [] && ! $this->permissions->allowsInProperty($actorId, StayService::IDENTITY_PERMISSION, $property)) {
            throw Refusal::forbidden('Correcting the identity needs the permission to see identity.');
        }

        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');
        $before = $this->guests->find($property, $stay->guestId) ?? throw Refusal::notFound('Guest not found.');
        $old = self::values($before);
        $new = $old;

        foreach ($changes as $field => $value) {
            $new[$field] = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
        }

        try {
            $after = new GuestProfile(
                $before->id, (string) $new['full_name'], (string) $new['nationality'], IdType::tryFrom((string) $new['id_type']) ?? throw StayRuleViolation::invalidGuest('Choose a valid identity document type.', 'id_type'),
                (string) $new['id_number'], $new['id_valid_until'] === null ? null : BusinessDate::fromString($new['id_valid_until']), $new['visa_number'], (string) $new['address'],
            );
        } catch (StayRuleViolation $e) {
            throw Refusal::invalid($e->getMessage(), [$e->field ?? 'changes']);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['id_valid_until']);
        }

        $stored = self::values($after);
        $fields = array_values(array_filter(self::FIELDS, static fn (string $f): bool => $old[$f] !== $stored[$f]));

        if ($fields === []) {
            throw Refusal::invalid('Nothing is different from what is recorded.', ['changes']);
        }

        return [
            'stay' => $stay, 'after' => $after, 'fields' => $fields, 'old' => $old, 'new' => $stored, 'reason' => $reason,
            // A keyed hash of the new values: the approval is bound to them without keeping them in the approval request.
            'hash' => $this->cipher->blindIndex('frontoffice.guest.correction', json_encode(array_intersect_key($stored, array_flip($fields)), JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function payload(array $plan): array
    {
        return ['stay_id' => $plan['stay']->id, 'fields' => $plan['fields'], 'values_hash' => $plan['hash']];
    }

    /** @return array<string, ?string> */
    private static function values(GuestProfile $g): array
    {
        return ['full_name' => $g->fullName, 'nationality' => $g->nationality, 'id_type' => $g->idType->value, 'id_number' => $g->idNumber, 'id_valid_until' => $g->idValidUntil?->toString(), 'visa_number' => $g->visaNumber, 'address' => $g->address];
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
