<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Waitlist;

use App\Modules\FrontOffice\Application\Waitlist\WaitlistStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseWaitlistStore implements WaitlistStore
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('waitlist_entries')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('waitlist_entries')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function entries(PropertyId $property, string $since): array
    {
        return DB::table('waitlist_entries')->where('property_id', $property->toString())
            ->where(static fn ($q) => $q->whereIn('status', ['waiting', 'offered'])->orWhere('updated_at', '>=', $since))
            ->orderBy('created_at')->orderBy('id')->limit(500)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('waitlist_entries')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }
}
