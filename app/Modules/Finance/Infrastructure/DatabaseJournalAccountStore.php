<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\JournalAccountStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseJournalAccountStore implements JournalAccountStore
{
    public function overrides(PropertyId $property): array
    {
        $out = [];

        foreach (DB::table('fin_journal_accounts')->where('property_id', $property->toString())->orderBy('account_key')->get() as $row) {
            $out[(string) $row->account_key] = ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'lock_version' => (int) $row->lock_version];
        }

        return $out;
    }

    public function save(PropertyId $property, string $id, string $key, string $code, string $name, ?int $lock, DateTimeImmutable $at): bool
    {
        if ($lock !== null) {
            return DB::table('fin_journal_accounts')->where('property_id', $property->toString())->where('account_key', $key)->where('lock_version', $lock)->update(['code' => $code, 'name' => $name, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
        }

        try {
            return DB::table('fin_journal_accounts')->insert(['id' => $id, 'property_id' => $property->toString(), 'account_key' => $key, 'code' => $code, 'name' => $name, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function remove(PropertyId $property, string $key, int $lock): bool
    {
        return DB::table('fin_journal_accounts')->where('property_id', $property->toString())->where('account_key', $key)->where('lock_version', $lock)->delete() === 1;
    }
}
