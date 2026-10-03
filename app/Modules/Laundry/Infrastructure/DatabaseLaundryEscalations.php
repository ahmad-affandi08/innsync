<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Infrastructure;

use App\Modules\Laundry\Application\LaundryEscalations;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseLaundryEscalations implements LaundryEscalations
{
    public function overdue(PropertyId $property, DateTimeImmutable $now, int $limit): array
    {
        return DB::table('laundry_orders as o')->join('rooms as r', 'r.id', '=', 'o.room_id')
            ->where('o.property_id', $property->toString())->whereIn('o.status', ['sent', 'received', 'washing', 'drying', 'ironing'])->where('o.promised_at', '<', $now)->whereNull('o.escalated_at')
            ->orderBy('o.promised_at')->orderBy('o.number')->limit($limit)
            ->get(['o.id', 'o.number', 'r.number as room_number', 'o.status', 'o.express', 'o.promised_at'])
            ->map(static fn (object $r): array => ['id' => (string) $r->id, 'number' => (string) $r->number, 'room_number' => (string) $r->room_number, 'status' => (string) $r->status, 'express' => (bool) $r->express, 'promised_at' => (string) $r->promised_at])->all();
    }

    public function markEscalated(PropertyId $property, string $orderId, DateTimeImmutable $at): bool
    {
        return DB::table('laundry_orders')->where('property_id', $property->toString())->where('id', $orderId)->whereNull('escalated_at')->update(['escalated_at' => $at]) === 1;
    }
}
