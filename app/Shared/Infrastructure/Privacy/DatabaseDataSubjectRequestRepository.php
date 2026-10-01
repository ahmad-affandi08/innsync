<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Privacy;

use App\Shared\Application\Privacy\DataSubjectRequest;
use App\Shared\Application\Privacy\DataSubjectRequestRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseDataSubjectRequestRepository implements DataSubjectRequestRepository
{
    public function add(PropertyId $property, DataSubjectRequest $request): void
    {
        DB::table('data_subject_requests')->insert([
            'id' => $request->id,
            'property_id' => $property->toString(),
            'subject_type' => $request->subjectType,
            'subject_id' => $request->subjectId,
            'request_type' => $request->type,
            'status' => $request->status,
            'channel' => $request->channel,
            'verification_note' => $request->verificationNote,
            'received_at' => $request->receivedAt,
            'due_at' => $request->dueAt,
            'created_by' => $request->createdBy,
            'lock_version' => 0,
            'created_at' => $request->receivedAt,
            'updated_at' => $request->receivedAt,
        ]);
    }

    public function find(PropertyId $property, string $id): ?DataSubjectRequest
    {
        $row = DB::table('data_subject_requests')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function save(PropertyId $property, DataSubjectRequest $request, int $expectedLockVersion): bool
    {
        return DB::table('data_subject_requests')
            ->where('property_id', $property->toString())
            ->where('id', $request->id)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                'status' => $request->status,
                'decision_basis' => $request->decisionBasis,
                'decision_note' => $request->decisionNote,
                'handled_by' => $request->handledBy,
                'completed_at' => $request->completedAt,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]) === 1;
    }

    public function open(PropertyId $property, int $limit): array
    {
        return DB::table('data_subject_requests')
            ->where('property_id', $property->toString())
            ->whereIn('status', [DataSubjectRequest::RECEIVED, DataSubjectRequest::IN_PROGRESS])
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->map(static fn (stdClass $row): DataSubjectRequest => self::hydrate($row))
            ->all();
    }

    private static function hydrate(stdClass $row): DataSubjectRequest
    {
        return new DataSubjectRequest(
            $row->id,
            $row->subject_type,
            $row->subject_id,
            $row->request_type,
            $row->status,
            $row->channel,
            $row->verification_note,
            CarbonImmutable::parse($row->received_at, 'UTC')->toImmutable(),
            CarbonImmutable::parse($row->due_at, 'UTC')->toImmutable(),
            $row->decision_basis,
            $row->decision_note,
            $row->handled_by,
            $row->completed_at === null ? null : CarbonImmutable::parse($row->completed_at, 'UTC')->toImmutable(),
            (int) $row->lock_version,
            $row->created_by,
        );
    }
}
