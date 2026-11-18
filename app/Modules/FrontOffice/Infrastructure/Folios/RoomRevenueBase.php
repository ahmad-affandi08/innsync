<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Folios;

use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

/**
 * The room revenue of a reservation that commission and loyalty points are paid on: what the night audit charged, less the reversals of those charges
 * (a reversal is stored with the negated amount and points at the posting it undoes).
 */
final class RoomRevenueBase
{
    public static function of(PropertyId $property, string $reservationId): int
    {
        return (int) DB::table('folio_postings as p')->join('folios as f', 'f.id', '=', 'p.folio_id')
            ->where('p.property_id', $property->toString())->where('f.reservation_id', $reservationId)
            ->where(static fn ($q) => $q->where('p.source', 'night_audit')
                ->orWhere(static fn ($r) => $r->where('p.entry_type', 'reversal')->whereIn('p.reverses_id', DB::table('folio_postings')->where('property_id', $property->toString())->where('source', 'night_audit')->select('id'))))
            ->sum('p.base_minor');
    }
}
