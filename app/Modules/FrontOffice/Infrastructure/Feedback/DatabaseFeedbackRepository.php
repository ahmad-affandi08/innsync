<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Feedback;

use App\Modules\FrontOffice\Application\Feedback\FeedbackRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseFeedbackRepository implements FeedbackRepository
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('guest_feedback')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = $this->base($property)->where('f.id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function findByKey(PropertyId $property, string $clientKey): ?array
    {
        $row = $this->base($property)->where('f.client_key', $clientKey)->first();

        return $row === null ? null : self::shape($row);
    }

    public function search(PropertyId $property, array $filters, int $limit): array
    {
        $query = $this->base($property);

        foreach (['kind', 'status', 'severity'] as $key) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $query->where('f.'.$key, $filters[$key]);
            }
        }

        if (($filters['owner_id'] ?? null) !== null && $filters['owner_id'] !== '') {
            $query->where('f.owner_id', $filters['owner_id']);
        }

        if ($filters['open_only'] ?? false) {
            $query->whereIn('f.status', ['open', 'in_progress']);
        }

        return $query->orderByRaw("CASE f.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE f.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc('f.created_at')->orderByDesc('f.id')->limit($limit)->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function change(PropertyId $property, string $id, int $expectedLockVersion, array $changes, DateTimeImmutable $at): bool
    {
        return DB::table('guest_feedback')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)->where('status', '<>', 'closed')
            ->update([...$changes, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function addEvent(PropertyId $property, string $id, string $feedbackId, string $kind, ?string $text, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('guest_feedback_events')->insert(['id' => $id, 'property_id' => $property->toString(), 'feedback_id' => $feedbackId, 'kind' => $kind, 'text' => $text, 'actor_id' => $actorId, 'created_at' => $at]);
    }

    public function events(PropertyId $property, string $feedbackId): array
    {
        return DB::table('guest_feedback_events')->where('property_id', $property->toString())->where('feedback_id', $feedbackId)->orderBy('created_at')->orderBy('id')->get()
            ->map(static fn ($e): array => ['id' => $e->id, 'kind' => $e->kind, 'text' => $e->text, 'actor_id' => $e->actor_id, 'created_at' => (new DateTimeImmutable((string) $e->created_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')])->all();
    }

    public function seriousOpen(PropertyId $property, int $limit): array
    {
        return DB::table('guest_feedback')->where('property_id', $property->toString())->where('kind', 'complaint')->whereIn('severity', ['high', 'critical'])->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->orderBy('created_at')->limit($limit)->get(['number', 'severity'])->map(static fn ($r): array => ['number' => $r->number, 'severity' => $r->severity])->all();
    }

    private function base(PropertyId $property): Builder
    {
        return DB::table('guest_feedback as f')->leftJoin('rooms', 'rooms.id', '=', 'f.room_id')->where('f.property_id', $property->toString())->select('f.*', 'rooms.number as room_number');
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r->id, 'number' => $r->number, 'kind' => $r->kind, 'severity' => $r->severity, 'channel' => $r->channel, 'reservation_id' => $r->reservation_id, 'stay_id' => $r->stay_id,
            'room_id' => $r->room_id, 'room' => $r->room_number, 'guest_name' => $r->guest_name, 'summary' => $r->summary, 'detail' => $r->detail, 'status' => $r->status, 'owner_id' => $r->owner_id,
            'follow_up_by' => $r->follow_up_by === null ? null : substr((string) $r->follow_up_by, 0, 10), 'resolution' => $r->resolution, 'evidence_ref' => $r->evidence_ref,
            'resolved_at' => $utc($r->resolved_at), 'closed_at' => $utc($r->closed_at), 'created_by' => $r->created_by, 'created_at' => $utc($r->created_at), 'lock_version' => (int) $r->lock_version,
        ];
    }
}
