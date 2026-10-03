<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\RecurringExpenseStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseRecurringExpenseStore implements RecurringExpenseStore
{
    public function all(PropertyId $property): array
    {
        return $this->rows($this->query($property)->orderBy('r.next_due')->orderBy('r.name')->get());
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = $this->query($property)->where('r.id', $id)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        $row['history'] = $this->rows(DB::table('fin_recurring_occurrences')->where('recurring_id', $id)->orderByDesc('due_date')->get());

        return $row;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('fin_recurring_expenses')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fin_recurring_expenses')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fin_recurring_expenses')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addOccurrence(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fin_recurring_occurrences')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function advance(PropertyId $property, string $id, string $nextDue, DateTimeImmutable $at): void
    {
        DB::table('fin_recurring_expenses')->where('property_id', $property->toString())->where('id', $id)->update(['next_due' => $nextDue, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $at]);
    }

    public function accounts(PropertyId $property): array
    {
        return $this->rows(DB::table('finance_expense_accounts')->where('property_id', $property->toString())->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'department', 'category']));
    }

    public function account(PropertyId $property, string $id): ?array
    {
        $row = DB::table('finance_expense_accounts')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    private function query(PropertyId $property): Builder
    {
        return DB::table('fin_recurring_expenses as r')->join('finance_expense_accounts as a', 'a.id', '=', 'r.expense_account_id')->where('r.property_id', $property->toString())
            ->select(['r.*', 'a.code as account_code', 'a.name as account_name', 'a.department', 'a.category']);
    }

    /** @param iterable<object> $rows @return list<array<string, mixed>> */
    private function rows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }
}
