<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Approval;

use App\Modules\IdentityAccess\Application\Approval\ApprovalRepository;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalDecision;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalRequest;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStatus;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStep;
use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Every query carries the property id explicitly; a request of another property is simply not found. */
final class DatabaseApprovalRepository implements ApprovalRepository
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public function add(ApprovalRequest $request, string $correlationId): void
    {
        $now = $request->createdAt->format(self::FORMAT);

        DB::table('approval_requests')->insert([
            'id' => $request->id,
            'property_id' => $request->propertyId,
            'subject_type' => $request->subjectType,
            'subject_ref' => $request->subjectRef,
            'maker_id' => $request->makerId,
            'reason' => $request->reason,
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
            'scope_type' => $request->scopeType,
            'scope_id' => $request->scopeId,
            'payload_hash' => $request->payloadHash,
            'payload' => json_encode($request->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'before_state' => $request->before === null ? null : json_encode($request->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'policy_id' => $request->policyId,
            'policy_steps' => json_encode(array_map(static fn (ApprovalStep $s): array => $s->toArray(), $request->steps), JSON_THROW_ON_ERROR),
            'status' => $request->status()->value,
            'current_step' => $request->currentStep(),
            'supersedes_id' => $request->supersedesId,
            'correlation_id' => strtolower($correlationId),
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function find(PropertyId $property, string $id): ?ApprovalRequest
    {
        $row = DB::table('approval_requests')
            ->where('property_id', $property->toString())
            ->where('id', strtolower($id))
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(ApprovalRequest $request, string $correlationId): void
    {
        $expected = $request->lockVersion();

        $updated = DB::table('approval_requests')
            ->where('id', $request->id)
            ->where('property_id', $request->propertyId)
            ->where('lock_version', $expected)
            ->update([
                'status' => $request->status()->value,
                'current_step' => $request->currentStep(),
                'completed_at' => $this->time($request->completedAt()),
                'consumed_at' => $this->time($request->consumedAt()),
                'lock_version' => $expected + 1,
                'updated_at' => CarbonImmutable::now('UTC')->format(self::FORMAT),
            ]);

        // Someone else decided, cancelled or consumed it first: never overwrite their change.
        if ($updated !== 1) {
            throw OptimisticLockConflict::forRecord('approval_request', $request->id, $expected);
        }

        foreach ($request->newDecisions() as $decision) {
            DB::table('approval_decisions')->insert([
                'id' => strtolower((string) Str::ulid()),
                'property_id' => $request->propertyId,
                'request_id' => $request->id,
                'step' => $decision->step,
                'approver_id' => $decision->approverId,
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'decided_at' => $decision->decidedAt->format(self::FORMAT),
                'correlation_id' => strtolower($correlationId),
            ]);
        }
    }

    public function pending(PropertyId $property, int $limit): array
    {
        return $this->many(DB::table('approval_requests')
            ->where('property_id', $property->toString())
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all());
    }

    public function byMaker(PropertyId $property, string $makerId, int $limit): array
    {
        return $this->many(DB::table('approval_requests')
            ->where('property_id', $property->toString())
            ->where('maker_id', strtolower($makerId))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all());
    }

    /**
     * @param  list<object>  $rows
     * @return list<ApprovalRequest>
     */
    private function many(array $rows): array
    {
        return array_map($this->hydrate(...), $rows);
    }

    private function hydrate(object $row): ApprovalRequest
    {
        $decisions = DB::table('approval_decisions')
            ->where('request_id', $row->id)
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $d): ApprovalDecision => new ApprovalDecision(
                (int) $d->step,
                (string) $d->approver_id,
                (string) $d->decision,
                $d->reason === null ? null : (string) $d->reason,
                $this->utc((string) $d->decided_at),
            ))
            ->all();

        return new ApprovalRequest(
            (string) $row->id,
            (string) $row->property_id,
            (string) $row->subject_type,
            (string) $row->subject_ref,
            (string) $row->maker_id,
            (string) $row->reason,
            (string) $row->payload_hash,
            (array) json_decode((string) $row->payload, true),
            $row->before_state === null ? null : (array) json_decode((string) $row->before_state, true),
            $row->amount_minor === null ? null : (int) $row->amount_minor,
            $row->currency === null ? null : (string) $row->currency,
            $row->scope_type === null ? null : (string) $row->scope_type,
            $row->scope_id === null ? null : (string) $row->scope_id,
            (string) $row->policy_id,
            array_map(ApprovalStep::fromArray(...), (array) json_decode((string) $row->policy_steps, true)),
            $row->supersedes_id === null ? null : (string) $row->supersedes_id,
            $this->utc((string) $row->created_at),
            ApprovalStatus::from((string) $row->status),
            (int) $row->current_step,
            array_values($decisions),
            $row->completed_at === null ? null : $this->utc((string) $row->completed_at),
            $row->consumed_at === null ? null : $this->utc((string) $row->consumed_at),
            (int) $row->lock_version,
        );
    }

    private function time(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : CarbonImmutable::instance($time)->setTimezone('UTC')->format(self::FORMAT);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }
}
