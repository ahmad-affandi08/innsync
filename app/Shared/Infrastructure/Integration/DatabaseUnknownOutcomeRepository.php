<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration;

use App\Shared\Application\Integration\UnknownOutcome;
use App\Shared\Application\Integration\UnknownOutcomeRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseUnknownOutcomeRepository implements UnknownOutcomeRepository
{
    public function record(PropertyId $property, UnknownOutcome $outcome): bool
    {
        try {
            DB::table('integration_unknowns')->insert([
                'id' => $outcome->id,
                'property_id' => $property->toString(),
                'provider' => $outcome->provider,
                'operation' => $outcome->operation,
                'idempotency_key' => $outcome->idempotencyKey,
                'correlation_id' => $outcome->correlationId,
                'status' => UnknownOutcome::OPEN,
                'created_at' => $outcome->createdAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Only the unique (provider, key) is expected to collide; any other error still surfaces.
            return false;
        }

        return true;
    }

    public function find(PropertyId $property, string $id): ?UnknownOutcome
    {
        $row = DB::table('integration_unknowns')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function resolve(PropertyId $property, string $id, string $status, string $actorId, string $reason, DateTimeImmutable $at): bool
    {
        return DB::table('integration_unknowns')
            ->where('property_id', $property->toString())
            ->where('id', $id)
            ->where('status', UnknownOutcome::OPEN)
            ->update(['status' => $status, 'resolved_by' => $actorId, 'resolved_at' => $at, 'resolution_reason' => $reason]) === 1;
    }

    public function open(PropertyId $property, int $limit): array
    {
        return DB::table('integration_unknowns')
            ->where('property_id', $property->toString())
            ->where('status', UnknownOutcome::OPEN)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (stdClass $row): UnknownOutcome => self::hydrate($row))
            ->all();
    }

    private static function hydrate(stdClass $row): UnknownOutcome
    {
        return new UnknownOutcome($row->id, $row->provider, $row->operation, $row->idempotency_key, $row->correlation_id, $row->status, CarbonImmutable::parse($row->created_at, 'UTC')->toImmutable());
    }
}
