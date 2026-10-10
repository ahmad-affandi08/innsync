<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Modules\HumanResource\Application\PayrollBankStore;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePayrollBankStore implements PayrollBankStore
{
    public function __construct(private FieldCipher $cipher) {}

    public function accounts(PropertyId $property): array
    {
        $out = [];

        foreach (DB::table('hr_bank_accounts')->where('property_id', $property->toString())->get(['employee_id', 'bank_name', 'account_holder', 'account_last4', 'lock_version']) as $r) {
            $out[(string) $r->employee_id] = ['bank_name' => (string) $r->bank_name, 'account_holder' => (string) $r->account_holder, 'account_last4' => (string) $r->account_last4, 'lock_version' => (int) $r->lock_version];
        }

        return $out;
    }

    public function numbers(PropertyId $property, array $employeeIds): array
    {
        $out = [];

        foreach (array_chunk($employeeIds, 500) as $chunk) {
            foreach (DB::table('hr_bank_accounts')->where('property_id', $property->toString())->whereIn('employee_id', $chunk)->get() as $r) {
                $out[(string) $r->employee_id] = ['bank_name' => (string) $r->bank_name, 'account_holder' => (string) $r->account_holder, 'account_number' => $this->cipher->open((string) $r->account_number_enc)];
            }
        }

        return $out;
    }

    public function save(PropertyId $property, string $employeeId, string $bankName, string $holder, string $number, ?int $lock, string $by, DateTimeImmutable $at): bool
    {
        $row = ['bank_name' => $bankName, 'account_holder' => $holder, 'account_number_enc' => $this->cipher->seal($number), 'account_last4' => substr($number, -4), 'updated_by' => $by, 'updated_at' => $at];

        if ($lock !== null) {
            return DB::table('hr_bank_accounts')->where('property_id', $property->toString())->where('employee_id', $employeeId)->where('lock_version', $lock)->update([...$row, 'lock_version' => $lock + 1]) === 1;
        }

        try {
            return DB::table('hr_bank_accounts')->insert([...$row, 'employee_id' => $employeeId, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
