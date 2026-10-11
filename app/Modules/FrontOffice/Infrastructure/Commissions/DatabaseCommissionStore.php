<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Commissions;

use App\Modules\FrontOffice\Application\Commissions\CommissionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCommissionStore implements CommissionStore
{
    public function addPartner(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('commission_partners')->insert([...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function partner(PropertyId $property, string $id): ?array
    {
        $r = DB::table('commission_partners')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function partnerOf(PropertyId $property, string $kind, string $key): ?array
    {
        $r = DB::table('commission_partners')->where('property_id', $property->toString())->where('partner_kind', $kind)->where('partner_key', $key)->first();

        return $r === null ? null : (array) $r;
    }

    public function partners(PropertyId $property): array
    {
        return DB::table('commission_partners')->where('property_id', $property->toString())->orderBy('name')->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function updatePartner(PropertyId $property, string $id, int $lock, int $rateBp, bool $active, DateTimeImmutable $at): bool
    {
        return DB::table('commission_partners')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update(['rate_bp' => $rateBp, 'is_active' => $active, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function partnerKeyOf(PropertyId $property, string $reservationId): ?array
    {
        $company = DB::table('reservation_companies')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->value('company_id');

        if (is_string($company)) {
            return ['company', $company];
        }

        $channel = DB::table('reservation_external_refs')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->value('channel');

        return is_string($channel) ? ['channel', strtolower($channel)] : null;
    }

    public function roomBaseOf(PropertyId $property, string $reservationId): int
    {
        return (int) DB::table('folio_postings as p')->join('folios as f', 'f.id', '=', 'p.folio_id')->where('p.property_id', $property->toString())->where('f.reservation_id', $reservationId)->where('p.source', 'night_audit')->sum('p.base_minor');
    }

    public function addAccrual(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('commission_accruals')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'accrued', 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function accrual(PropertyId $property, string $id): ?array
    {
        $r = DB::table('commission_accruals')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function accruals(PropertyId $property, ?string $partnerId, ?string $status, int $limit): array
    {
        $q = DB::table('commission_accruals as a')->join('commission_partners as p', 'p.id', '=', 'a.partner_id')->where('a.property_id', $property->toString());

        if ($partnerId !== null) {
            $q->where('a.partner_id', $partnerId);
        }

        if ($status !== null) {
            $q->where('a.status', $status);
        }

        return $q->orderByDesc('a.stay_out_on')->orderByDesc('a.id')->limit($limit)->get(['a.*', 'p.name as partner_name'])->map(static fn (object $r): array => (array) $r)->all();
    }

    public function accruedOf(PropertyId $property, string $partnerId, array $ids): array
    {
        $q = DB::table('commission_accruals')->where('property_id', $property->toString())->where('partner_id', $partnerId)->where('status', 'accrued')->orderBy('stay_out_on')->orderBy('id')->lockForUpdate();

        if ($ids !== []) {
            $q->whereIn('id', $ids);
        }

        return $q->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function voidAccrual(PropertyId $property, string $id, string $reason, string $by, DateTimeImmutable $at): bool
    {
        return DB::table('commission_accruals')->where('property_id', $property->toString())->where('id', $id)->where('status', 'accrued')->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => $by, 'updated_at' => $at]) === 1;
    }

    public function addStatement(PropertyId $property, array $row, array $accrualIds, DateTimeImmutable $at): void
    {
        DB::table('commission_statements')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
        DB::table('commission_accruals')->where('property_id', $property->toString())->whereIn('id', $accrualIds)->where('status', 'accrued')->update(['status' => 'paid', 'statement_id' => $row['id'], 'updated_at' => $at]);
    }

    public function owedByPartner(PropertyId $property): array
    {
        return DB::table('commission_accruals')->where('property_id', $property->toString())->where('status', 'accrued')->groupBy('partner_id')->selectRaw('partner_id, SUM(commission_minor) as owed')->pluck('owed', 'partner_id')->map(static fn ($n): int => (int) $n)->all();
    }
}
