<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Folios;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Domain\Folios\EntryType;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\PaymentMethod;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseFolioRepository implements FolioRepository
{
    public function create(PropertyId $property, Folio $folio, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('folios')->insert([
                'id' => $folio->id, 'property_id' => $property->toString(), 'number' => $folio->number, 'reservation_id' => $folio->reservationId,
                'window_no' => $folio->window, 'label' => $folio->label, 'currency_code' => $folio->currency, 'status' => 'open', 'balance_minor' => 0,
                'last_seq' => 0, 'created_by' => $actorId, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function find(PropertyId $property, string $id): ?Folio
    {
        $row = DB::table('folios')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::folio($row);
    }

    public function lock(PropertyId $property, string $id): ?Folio
    {
        $row = DB::table('folios')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();

        return $row === null ? null : self::folio($row);
    }

    public function byReservation(PropertyId $property, string $reservationId): array
    {
        return DB::table('folios')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->orderBy('window_no')->get()
            ->map(static fn (stdClass $r): Folio => self::folio($r))->all();
    }

    public function append(PropertyId $property, Folio $locked, Posting $posting): Posting
    {
        $seq = $locked->lastSeq + 1;
        $saved = $posting->withSeq($seq);

        DB::table('folio_postings')->insert([
            'id' => $saved->id, 'property_id' => $property->toString(), 'folio_id' => $locked->id, 'seq' => $seq, 'entry_type' => $saved->type->value, 'code' => $saved->code,
            'description' => $saved->description, 'currency_code' => $saved->total->currency, 'base_minor' => $saved->base->amountMinor,
            'service_charge_minor' => $saved->serviceCharge->amountMinor, 'tax_minor' => $saved->tax->amountMinor, 'total_minor' => $saved->total->amountMinor,
            'business_date' => $saved->businessDate->toString(), 'posted_at' => $saved->postedAt, 'posted_by' => $saved->postedBy, 'source' => $saved->source,
            'source_ref' => $saved->sourceRef, 'reverses_id' => $saved->reversesId, 'reason' => $saved->reason, 'payment_method' => $saved->method?->value,
            'payment_reference' => $saved->methodReference, 'payment_purpose' => $saved->purpose, 'approval_id' => $saved->approvalId,
            'scheme_snapshot' => $saved->schemeSnapshot === null ? null : json_encode($saved->schemeSnapshot, JSON_THROW_ON_ERROR),
        ]);

        // The balance and the sequence move under the row lock the caller holds.
        DB::table('folios')->where('id', $locked->id)->update([
            'balance_minor' => $locked->balance->amountMinor + $saved->total->amountMinor, 'last_seq' => $seq, 'lock_version' => $locked->lockVersion + 1, 'updated_at' => $saved->postedAt,
        ]);

        return $saved;
    }

    public function postings(PropertyId $property, string $folioId): array
    {
        return DB::table('folio_postings')->where('property_id', $property->toString())->where('folio_id', $folioId)->orderBy('seq')->get()
            ->map(static fn (stdClass $r): Posting => self::posting($r))->all();
    }

    public function findPosting(PropertyId $property, string $postingId): ?array
    {
        $row = DB::table('folio_postings')->where('property_id', $property->toString())->where('id', $postingId)->first();

        return $row === null ? null : [$row->folio_id, self::posting($row)];
    }

    public function findBySource(PropertyId $property, string $source, string $sourceRef): ?array
    {
        $row = DB::table('folio_postings')->where('property_id', $property->toString())->where('source', $source)->where('source_ref', $sourceRef)->first();

        return $row === null ? null : [$row->folio_id, self::posting($row)];
    }

    public function isReversed(PropertyId $property, string $postingId): bool
    {
        return DB::table('folio_postings')->where('property_id', $property->toString())->where('reverses_id', $postingId)->exists();
    }

    public function hasPayments(PropertyId $property, string $folioId): bool
    {
        return DB::table('folio_postings')->where('property_id', $property->toString())->where('folio_id', $folioId)->where('entry_type', 'payment')->exists();
    }

    public function depositHeldMinor(PropertyId $property, string $reservationId): int
    {
        $base = DB::table('folio_postings as p')->join('folios as f', 'f.id', '=', 'p.folio_id')->where('p.property_id', $property->toString())->where('f.reservation_id', $reservationId);

        $paid = (int) (clone $base)->where('p.entry_type', 'payment')->where('p.payment_purpose', 'deposit')
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('folio_postings as r')->whereColumn('r.reverses_id', 'p.id'))->sum(DB::raw('-p.total_minor'));
        $paidBack = (int) (clone $base)->where('p.entry_type', 'refund')->sum('p.total_minor');

        return max(0, $paid - $paidBack);
    }

    public function close(PropertyId $property, Folio $folio, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('folios')->where('property_id', $property->toString())->where('id', $folio->id)->where('lock_version', $folio->lockVersion)->where('status', 'open')->update([
            'status' => 'closed', 'closed_at' => $at, 'closed_by' => $actorId, 'lock_version' => $folio->lockVersion + 1, 'updated_at' => $at,
        ]) === 1;
    }

    public function recomputeBalance(PropertyId $property, string $folioId): int
    {
        return (int) DB::table('folio_postings')->where('property_id', $property->toString())->where('folio_id', $folioId)->sum('total_minor');
    }

    private static function folio(stdClass $r): Folio
    {
        return new Folio($r->id, $r->number, $r->reservation_id, (int) $r->window_no, $r->label, $r->currency_code, $r->status === 'closed', Money::ofMinor((int) $r->balance_minor, $r->currency_code), (int) $r->last_seq, (int) $r->lock_version);
    }

    private static function posting(stdClass $r): Posting
    {
        $c = $r->currency_code;

        return Posting::restore(
            $r->id, EntryType::from($r->entry_type), $r->code, $r->description,
            Money::ofMinor((int) $r->base_minor, $c), Money::ofMinor((int) $r->service_charge_minor, $c), Money::ofMinor((int) $r->tax_minor, $c), Money::ofMinor((int) $r->total_minor, $c),
            BusinessDate::fromString(substr((string) $r->business_date, 0, 10)), CarbonImmutable::parse($r->posted_at, 'UTC')->toImmutable(), $r->posted_by, $r->source, $r->source_ref,
            $r->reverses_id, $r->reason, $r->payment_method === null ? null : PaymentMethod::from($r->payment_method), $r->payment_reference, $r->payment_purpose, $r->approval_id,
            $r->scheme_snapshot === null ? null : json_decode((string) $r->scheme_snapshot, true, 512, JSON_THROW_ON_ERROR), (int) $r->seq,
        );
    }
}
