<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Companies;

use App\Modules\FrontOffice\Application\Companies\CompanyRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCompanyRepository implements CompanyRepository
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('company_profiles')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function update(PropertyId $property, string $id, array $values, int $expectedLockVersion, DateTimeImmutable $at): bool
    {
        return DB::table('company_profiles')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)
            ->update([...$values, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('company_profiles')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function list(PropertyId $property, bool $activeOnly): array
    {
        $query = DB::table('company_profiles')->where('property_id', $property->toString());

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function companyOf(PropertyId $property, string $reservationId): ?array
    {
        $row = DB::table('reservation_companies as rc')->join('company_profiles as c', 'c.id', '=', 'rc.company_id')->where('rc.property_id', $property->toString())->where('rc.reservation_id', $reservationId)->first(['c.*']);

        return $row === null ? null : self::shape($row);
    }

    public function link(PropertyId $property, string $reservationId, string $companyId, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('reservation_companies')->insert(['reservation_id' => $reservationId, 'property_id' => $property->toString(), 'company_id' => $companyId, 'linked_by' => $actorId, 'linked_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function companyFolioId(PropertyId $property, string $reservationId): ?string
    {
        $id = DB::table('company_folios')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->value('folio_id');

        return is_string($id) ? $id : null;
    }

    public function markCompanyFolio(PropertyId $property, string $folioId, string $reservationId, string $companyId, DateTimeImmutable $at): void
    {
        DB::table('company_folios')->insert(['folio_id' => $folioId, 'property_id' => $property->toString(), 'reservation_id' => $reservationId, 'company_id' => $companyId, 'created_at' => $at]);
    }

    public function isCompanyFolio(PropertyId $property, string $folioId): bool
    {
        return DB::table('company_folios')->where('property_id', $property->toString())->where('folio_id', $folioId)->exists();
    }

    public function openFolios(PropertyId $property): array
    {
        return DB::table('company_folios as cf')->join('folios as f', 'f.id', '=', 'cf.folio_id')->join('reservations as r', 'r.id', '=', 'cf.reservation_id')
            ->where('cf.property_id', $property->toString())->where('f.status', 'open')->orderBy('f.created_at')
            ->get(['cf.company_id', 'f.id as folio_id', 'f.number as folio_number', 'r.id as reservation_id', 'r.number as reservation_number', 'r.guest_name', 'f.balance_minor', 'f.currency_code', 'f.created_at'])
            ->map(static fn ($r): array => [
                'company_id' => $r->company_id, 'folio_id' => $r->folio_id, 'folio_number' => $r->folio_number, 'reservation_id' => $r->reservation_id, 'reservation_number' => $r->reservation_number,
                'guest' => (string) $r->guest_name, 'balance_minor' => (int) $r->balance_minor, 'currency' => $r->currency_code, 'since' => substr((string) $r->created_at, 0, 10),
            ])->all();
    }

    public function highestWindow(PropertyId $property, string $reservationId): int
    {
        return (int) DB::table('folios')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->max('window_no');
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return [
            'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'kind' => $r->kind, 'contact_name' => $r->contact_name, 'contact_phone' => $r->contact_phone, 'contact_email' => $r->contact_email,
            'tax_id' => $r->tax_id, 'billing_instruction' => $r->billing_instruction, 'credit_limit_minor' => $r->credit_limit_minor === null ? null : (int) $r->credit_limit_minor,
            'route_rooms' => (bool) $r->route_rooms, 'route_extras' => (bool) $r->route_extras, 'is_active' => (bool) $r->is_active, 'lock_version' => (int) $r->lock_version,
        ];
    }
}
