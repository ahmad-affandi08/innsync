<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\ForeignPayments;

use App\Modules\FrontOffice\Application\ForeignPayments\ForeignPaymentRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseForeignPaymentRepository implements ForeignPaymentRepository
{
    public function settings(PropertyId $property): array
    {
        $row = DB::table('foreign_payment_settings')->where('property_id', $property->toString())->first();

        return ['enabled' => $row !== null && (bool) $row->enabled, 'lock_version' => $row === null ? 0 : (int) $row->lock_version];
    }

    public function setEnabled(PropertyId $property, bool $enabled, int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool
    {
        $pid = $property->toString();

        if (DB::table('foreign_payment_settings')->where('property_id', $pid)->doesntExist()) {
            if ($expectedLockVersion !== 0) {
                return false;
            }

            DB::table('foreign_payment_settings')->insertOrIgnore(['property_id' => $pid, 'enabled' => false, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        }

        return DB::table('foreign_payment_settings')->where('property_id', $pid)->where('lock_version', $expectedLockVersion)
            ->update(['enabled' => $enabled, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $actorId, 'updated_at' => $at]) === 1;
    }

    public function currentRate(PropertyId $property, string $currency): ?array
    {
        $row = DB::table('exchange_rates')->where('property_id', $property->toString())->where('currency_code', $currency)->orderByDesc('version')->first();

        return $row === null ? null : self::rate($row);
    }

    public function currentRates(PropertyId $property): array
    {
        $latest = DB::table('exchange_rates')->where('property_id', $property->toString())->groupBy('currency_code')->select('currency_code', DB::raw('MAX(version) as version'));

        return DB::table('exchange_rates as e')->joinSub($latest, 'l', fn ($j) => $j->on('l.currency_code', '=', 'e.currency_code')->on('l.version', '=', 'e.version'))
            ->where('e.property_id', $property->toString())->orderBy('e.currency_code')->get(['e.*'])->map(static fn ($r): array => self::rate($r))->all();
    }

    public function addRate(PropertyId $property, string $id, string $currency, int $rateE4, string $reason, string $actorId, DateTimeImmutable $at): int
    {
        $version = (int) DB::table('exchange_rates')->where('property_id', $property->toString())->where('currency_code', $currency)->lockForUpdate()->max('version') + 1;
        DB::table('exchange_rates')->insert(['id' => $id, 'property_id' => $property->toString(), 'currency_code' => $currency, 'version' => $version, 'rate_e4' => $rateE4, 'reason' => $reason, 'set_by' => $actorId, 'created_at' => $at]);

        return $version;
    }

    public function record(PropertyId $property, string $postingId, string $currency, int $foreignMinor, int $rateE4, int $rateVersion, int $bookedMinor, DateTimeImmutable $at): void
    {
        DB::table('foreign_payments')->insertOrIgnore(['posting_id' => $postingId, 'property_id' => $property->toString(), 'currency_code' => $currency, 'foreign_minor' => $foreignMinor, 'rate_e4' => $rateE4, 'rate_version' => $rateVersion, 'booked_minor' => $bookedMinor, 'created_at' => $at]);
    }

    public function recorded(PropertyId $property, string $postingId): bool
    {
        return DB::table('foreign_payments')->where('property_id', $property->toString())->where('posting_id', $postingId)->exists();
    }

    public function recent(PropertyId $property, int $limit): array
    {
        return DB::table('foreign_payments as f')->join('folio_postings as p', 'p.id', '=', 'f.posting_id')->join('folios as fo', 'fo.id', '=', 'p.folio_id')
            ->where('f.property_id', $property->toString())->orderByDesc('f.created_at')->orderByDesc('f.posting_id')->limit($limit)
            ->get(['f.posting_id', 'f.currency_code', 'f.foreign_minor', 'f.rate_e4', 'f.rate_version', 'f.booked_minor', 'p.business_date', 'fo.id as folio_id', 'fo.number as folio_number', 'p.currency_code as booked_currency',
                DB::raw('EXISTS (SELECT 1 FROM folio_postings r WHERE r.reverses_id = p.id) as reversed')])
            ->map(static fn ($r): array => [
                'posting_id' => $r->posting_id, 'currency' => $r->currency_code, 'foreign_minor' => (int) $r->foreign_minor, 'rate_e4' => (int) $r->rate_e4, 'rate_version' => (int) $r->rate_version,
                'booked_minor' => (int) $r->booked_minor, 'booked_currency' => $r->booked_currency, 'business_date' => substr((string) $r->business_date, 0, 10), 'folio_id' => $r->folio_id, 'folio_number' => $r->folio_number, 'reversed' => (bool) $r->reversed,
            ])->all();
    }

    public function takenOn(PropertyId $property, string $businessDate): array
    {
        return DB::table('foreign_payments as f')->join('folio_postings as p', 'p.id', '=', 'f.posting_id')
            ->where('f.property_id', $property->toString())->where('p.business_date', $businessDate)
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('folio_postings as r')->whereColumn('r.reverses_id', 'p.id'))
            ->groupBy('f.currency_code')->orderBy('f.currency_code')
            ->get(['f.currency_code', DB::raw('SUM(f.foreign_minor) as foreign_minor'), DB::raw('SUM(f.booked_minor) as booked_minor'), DB::raw('COUNT(*) as n')])
            ->map(static fn ($r): array => ['currency' => $r->currency_code, 'foreign_minor' => (int) $r->foreign_minor, 'booked_minor' => (int) $r->booked_minor, 'count' => (int) $r->n])->all();
    }

    /** @return array{currency: string, version: int, rate_e4: int, reason: string, created_at: string} */
    private static function rate(object $r): array
    {
        return ['currency' => $r->currency_code, 'version' => (int) $r->version, 'rate_e4' => (int) $r->rate_e4, 'reason' => $r->reason, 'created_at' => (new DateTimeImmutable((string) $r->created_at))->format('Y-m-d\TH:i:s\Z')];
    }
}
