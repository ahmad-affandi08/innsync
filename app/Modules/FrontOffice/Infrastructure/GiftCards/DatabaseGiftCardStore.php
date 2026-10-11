<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\GiftCards;

use App\Modules\FrontOffice\Application\GiftCards\GiftCardStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseGiftCardStore implements GiftCardStore
{
    public function byHash(PropertyId $property, string $hash): ?array
    {
        $r = DB::table('gift_cards')->where('property_id', $property->toString())->where('code_hash', $hash)->first();

        return $r === null ? null : (array) $r;
    }

    public function card(PropertyId $property, string $id): ?array
    {
        $r = DB::table('gift_cards')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function addCard(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('gift_cards')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function debit(PropertyId $property, string $id, int $amount, DateTimeImmutable $at): bool
    {
        return DB::table('gift_cards')->where('property_id', $property->toString())->where('id', $id)->where('status', 'active')->where('balance_minor', '>=', $amount)
            ->update(['balance_minor' => DB::raw('balance_minor - '.(int) $amount), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $at]) === 1;
    }

    public function credit(PropertyId $property, string $id, int $amount, DateTimeImmutable $at): void
    {
        DB::table('gift_cards')->where('property_id', $property->toString())->where('id', $id)->update(['balance_minor' => DB::raw('LEAST(face_minor, balance_minor + '.(int) $amount.')'), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $at]);
    }

    public function voidCard(PropertyId $property, string $id, DateTimeImmutable $at): ?int
    {
        $row = DB::table('gift_cards')->where('property_id', $property->toString())->where('id', $id)->where('status', 'active')->lockForUpdate()->first();

        if ($row === null) {
            return null;
        }

        DB::table('gift_cards')->where('id', $id)->update(['status' => 'void', 'balance_minor' => 0, 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => $at]);

        return (int) $row->balance_minor;
    }

    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('gift_card_entries')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function entryBySource(PropertyId $property, string $kind, string $sourceType, string $sourceRef): ?array
    {
        $r = DB::table('gift_card_entries')->where('property_id', $property->toString())->where('kind', $kind)->where('source_type', $sourceType)->where('source_ref', $sourceRef)->first();

        return $r === null ? null : (array) $r;
    }

    public function cards(PropertyId $property, int $limit): array
    {
        return DB::table('gift_cards')->where('property_id', $property->toString())->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function entries(PropertyId $property, string $cardId): array
    {
        return DB::table('gift_card_entries')->where('property_id', $property->toString())->where('card_id', $cardId)->orderBy('created_at')->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function openShiftOf(PropertyId $property, string $cashierId): ?string
    {
        $id = DB::table('cashier_shifts')->where('property_id', $property->toString())->where('cashier_id', $cashierId)->where('status', 'open')->value('id');

        return is_string($id) ? $id : null;
    }
}
