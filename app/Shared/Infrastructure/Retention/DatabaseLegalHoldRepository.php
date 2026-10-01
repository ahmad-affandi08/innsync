<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Retention;

use App\Shared\Application\Retention\LegalHold;
use App\Shared\Application\Retention\LegalHoldRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseLegalHoldRepository implements LegalHoldRepository
{
    public function add(PropertyId $property, LegalHold $hold): void
    {
        DB::table('legal_holds')->insert([
            'id' => $hold->id,
            'property_id' => $property->toString(),
            'scope_type' => $hold->scopeType,
            'purpose' => $hold->purpose,
            'owner_type' => $hold->ownerType,
            'owner_id' => $hold->ownerId,
            'reason' => $hold->reason,
            'placed_by' => $hold->placedBy,
            'placed_at' => $hold->placedAt,
        ]);
    }

    public function find(PropertyId $property, string $holdId): ?LegalHold
    {
        $row = DB::table('legal_holds')->where('property_id', $property->toString())->where('id', $holdId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function release(PropertyId $property, string $holdId, string $actorId, string $reason, DateTimeImmutable $at): bool
    {
        return DB::table('legal_holds')
            ->where('property_id', $property->toString())
            ->where('id', $holdId)
            ->whereNull('released_at')
            ->update(['released_at' => $at, 'released_by' => $actorId, 'release_reason' => $reason]) === 1;
    }

    public function active(PropertyId $property): array
    {
        return DB::table('legal_holds')
            ->where('property_id', $property->toString())
            ->whereNull('released_at')
            ->get()
            ->map(static fn (stdClass $row): LegalHold => self::hydrate($row))
            ->all();
    }

    private static function hydrate(stdClass $row): LegalHold
    {
        return new LegalHold(
            $row->id,
            $row->scope_type,
            $row->purpose,
            $row->owner_type,
            $row->owner_id,
            $row->reason,
            $row->placed_by,
            CarbonImmutable::parse($row->placed_at, 'UTC')->toImmutable(),
            $row->released_at === null ? null : CarbonImmutable::parse($row->released_at, 'UTC')->toImmutable(),
        );
    }
}
