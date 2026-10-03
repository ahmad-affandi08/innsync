<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Infrastructure;

use App\Modules\Kitchen\Application\TicketStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseTicketStore implements TicketStore
{
    public function addTicket(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        try {
            DB::table('kitchen_tickets')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'new', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }

        foreach ($lines as $line) {
            DB::table('kitchen_ticket_lines')->insert([...$line, 'ticket_id' => $row['id'], 'modifiers' => json_encode($line['modifiers'], JSON_THROW_ON_ERROR)]);
        }

        return true;
    }

    public function ticket(PropertyId $property, string $id): ?array
    {
        $row = DB::table('kitchen_tickets')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : $this->withLines([(array) $row])[0];
    }

    public function lockTicket(PropertyId $property, string $id): void
    {
        DB::table('kitchen_tickets')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function advance(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('kitchen_tickets')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->whereNotIn('status', ['served', 'cancelled'])
            ->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function open(PropertyId $property, string $station): array
    {
        return $this->withLines(DB::table('kitchen_tickets')->where('property_id', $property->toString())->where('station', $station)->whereIn('status', ['new', 'preparing', 'ready'])->orderBy('received_at')->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all());
    }

    public function servedSince(PropertyId $property, string $station, DateTimeImmutable $since, int $limit): array
    {
        return $this->withLines(DB::table('kitchen_tickets')->where('property_id', $property->toString())->where('station', $station)->where('status', 'served')->where('served_at', '>=', $since)->orderByDesc('served_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all());
    }

    public function openCounts(PropertyId $property): array
    {
        return DB::table('kitchen_tickets')->where('property_id', $property->toString())->whereIn('status', ['new', 'preparing', 'ready'])->groupBy('station')->pluck(DB::raw('COUNT(*)'), 'station')->map(static fn ($n): int => (int) $n)->all();
    }

    public function cancelLines(PropertyId $property, array $lineIds, DateTimeImmutable $at): array
    {
        if ($lineIds === []) {
            return [];
        }

        $tickets = DB::table('kitchen_ticket_lines as l')->join('kitchen_tickets as t', 't.id', '=', 'l.ticket_id')->where('t.property_id', $property->toString())->whereIn('l.line_id', $lineIds)->distinct()->pluck('t.id')->all();
        DB::table('kitchen_ticket_lines')->whereIn('line_id', $lineIds)->whereIn('ticket_id', $tickets)->update(['cancelled' => true]);
        $changed = [];

        foreach ($tickets as $id) {
            if (! DB::table('kitchen_ticket_lines')->where('ticket_id', $id)->where('cancelled', false)->exists()) {
                DB::table('kitchen_tickets')->where('id', $id)->whereIn('status', ['new', 'preparing', 'ready'])->update(['status' => 'cancelled', 'updated_at' => $at]);
            }

            $changed[] = (string) $id;
        }

        return $changed;
    }

    public function cancelBill(PropertyId $property, string $billId, DateTimeImmutable $at): array
    {
        $ids = DB::table('kitchen_tickets')->where('property_id', $property->toString())->where('bill_id', $billId)->whereIn('status', ['new', 'preparing', 'ready'])->pluck('id')->all();

        if ($ids !== []) {
            DB::table('kitchen_ticket_lines')->whereIn('ticket_id', $ids)->update(['cancelled' => true]);
            DB::table('kitchen_tickets')->whereIn('id', $ids)->update(['status' => 'cancelled', 'updated_at' => $at]);
        }

        return array_map('strval', $ids);
    }

    public function settings(PropertyId $property): ?array
    {
        $row = DB::table('kitchen_settings')->where('property_id', $property->toString())->first();

        return $row === null ? null : ['late_after_minutes' => (int) $row->late_after_minutes, 'stock_location_id' => $row->stock_location_id, 'lock_version' => (int) $row->lock_version];
    }

    public function saveSettings(PropertyId $property, int $lateAfterMinutes, ?string $stockLocationId, ?int $expectedLockVersion, string $by, DateTimeImmutable $at): bool
    {
        if ($expectedLockVersion === null) {
            try {
                DB::table('kitchen_settings')->insert(['property_id' => $property->toString(), 'late_after_minutes' => $lateAfterMinutes, 'stock_location_id' => $stockLocationId, 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]);

                return true;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return false;
                }

                throw $e;
            }
        }

        return DB::table('kitchen_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLockVersion)
            ->update(['late_after_minutes' => $lateAfterMinutes, 'stock_location_id' => $stockLocationId, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $by, 'updated_at' => $at]) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return list<array<string, mixed>>
     */
    private function withLines(array $tickets): array
    {
        $lines = DB::table('kitchen_ticket_lines')->whereIn('ticket_id', array_column($tickets, 'id'))->orderBy('id')->get()->groupBy('ticket_id');

        foreach ($tickets as &$t) {
            $t['lines'] = [];

            foreach ($lines[$t['id']] ?? [] as $l) {
                $l = (array) $l;
                $l['modifiers'] = json_decode((string) $l['modifiers'], true) ?? [];
                $t['lines'][] = $l;
            }
        }

        return $tickets;
    }
}
