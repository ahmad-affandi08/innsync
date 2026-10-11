<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Loyalty;

use App\Modules\FrontOffice\Application\Loyalty\LoyaltyStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseLoyaltyStore implements LoyaltyStore
{
    public function accounts(PropertyId $property, int $limit): array
    {
        return DB::table('loyalty_accounts')->where('property_id', $property->toString())->orderByDesc('balance_points')->orderBy('guest_name')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function account(PropertyId $property, string $id): ?array
    {
        $row = DB::table('loyalty_accounts')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function accountOfGuest(PropertyId $property, string $guestKey): ?array
    {
        $row = DB::table('loyalty_accounts')->where('property_id', $property->toString())->where('guest_key', $guestKey)->first();

        return $row === null ? null : (array) $row;
    }

    public function ensureAccount(PropertyId $property, string $id, string $guestKey, string $name, ?string $phoneLast4, DateTimeImmutable $at): string
    {
        try {
            DB::table('loyalty_accounts')->insert(['id' => $id, 'property_id' => $property->toString(), 'guest_key' => $guestKey, 'guest_name' => mb_substr($name, 0, 120), 'phone_last4' => $phoneLast4, 'balance_points' => 0, 'earned_points' => 0, 'created_at' => $at, 'updated_at' => $at]);

            return $id;
        } catch (UniqueConstraintViolationException) {
            return (string) DB::table('loyalty_accounts')->where('property_id', $property->toString())->where('guest_key', $guestKey)->value('id');
        }
    }

    public function move(PropertyId $property, string $accountId, int $points, DateTimeImmutable $at): bool
    {
        $query = DB::table('loyalty_accounts')->where('property_id', $property->toString())->where('id', $accountId);

        if ($points >= 0) {
            return $query->update(['balance_points' => DB::raw('balance_points + '.$points), 'earned_points' => DB::raw('earned_points + '.$points), 'updated_at' => $at]) === 1;
        }

        return $query->where('balance_points', '>=', -$points)->update(['balance_points' => DB::raw('balance_points - '.(-$points)), 'updated_at' => $at]) === 1;
    }

    public function balance(PropertyId $property, string $accountId): int
    {
        return (int) DB::table('loyalty_accounts')->where('property_id', $property->toString())->where('id', $accountId)->value('balance_points');
    }

    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('loyalty_entries')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function entryBySource(PropertyId $property, string $kind, string $sourceType, string $sourceRef): ?array
    {
        $row = DB::table('loyalty_entries')->where('property_id', $property->toString())->where('kind', $kind)->where('source_type', $sourceType)->where('source_ref', $sourceRef)->first();

        return $row === null ? null : (array) $row;
    }

    public function entries(PropertyId $property, string $accountId, int $limit): array
    {
        return DB::table('loyalty_entries')->where('property_id', $property->toString())->where('account_id', $accountId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function idleAccounts(PropertyId $property, DateTimeImmutable $since, int $limit): array
    {
        $active = DB::table('loyalty_entries')->where('property_id', $property->toString())->whereIn('kind', ['earn', 'redeem'])->where('created_at', '>=', $since)->select('account_id');

        return DB::table('loyalty_accounts')->where('property_id', $property->toString())->where('balance_points', '>', 0)->where('created_at', '<', $since)->whereNotIn('id', $active)->orderBy('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function propertiesWithAccounts(): array
    {
        return DB::table('loyalty_accounts')->distinct()->pluck('property_id')->map(static fn ($id): string => (string) $id)->all();
    }

    public function roomBaseOf(PropertyId $property, string $reservationId): int
    {
        return (int) DB::table('folio_postings as p')->join('folios as f', 'f.id', '=', 'p.folio_id')->where('p.property_id', $property->toString())->where('f.reservation_id', $reservationId)->where('p.source', 'night_audit')->sum('p.base_minor');
    }
}
