<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Modules\FrontOffice\Application\BookingRefused;
use App\Modules\FrontOffice\Domain\Reservations\ReservationStatus;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Import\BatchImport;
use App\Shared\Application\Import\CsvRows;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Brings in the bookings a channel (an online travel agent, a corporate booking tool) sent as a file, one way: the channel's file in, nothing back out (PRD 4.2, FR-FO-003). Every row goes through the same
 * `ReservationService` a person uses at the desk, so the dates, the occupancy, the minimum stay, the availability and the booking policy hold for an imported booking as for a typed one, and a row the hotel
 * cannot honour is listed with its line and nothing is imported. The channel's code of the booking is kept, so the same booking sent again (the same file, or a later file with the same booking) changes
 * nothing, and a row marked cancelled cancels the booking that was imported from it. The price is the hotel's own price for the rate plan; what the channel says it came to is kept in the notes to compare.
 */
final readonly class ReservationImport
{
    public const TEMPLATE = "channel,external_ref,status,guest_name,phone,email,arrival,departure,adults,children,room_type_code,rate_plan_code,channel_total,notes\nbooking_com,BK-12345678,confirmed,Budi Santoso,081234567890,budi@example.com,2026-11-10,2026-11-12,2,0,STD,OTA,1450000,Late arrival\nbooking_com,BK-12345679,cancelled,Sari Wulandari,,sari@example.com,2026-11-15,2026-11-16,1,0,STD,OTA,725000,\n";

    private const REQUIRED = ['channel', 'external_ref', 'guest_name', 'arrival', 'departure', 'adults', 'room_type_code', 'rate_plan_code'];

    private const OPTIONAL = ['status', 'phone', 'email', 'children', 'channel_total', 'notes'];

    public function __construct(
        private ReservationService $reservations,
        private ReservationExternalRefs $refs,
        private RoomCatalogReader $rooms,
        private RatePlanReader $plans,
        private BatchImport $batch,
        private Clock $clock,
    ) {}

    /** @return array{status: string, dry_run: bool, count: int, errors: list<array{line: int, code: string, message: string}>, summary: array{created: int, cancelled: int, skipped: int}} */
    public function run(PropertyId $property, string $actorId, string $csv, bool $dryRun): array
    {
        $parsed = CsvRows::parse($csv, self::REQUIRED, self::OPTIONAL);
        $summary = ['created' => 0, 'cancelled' => 0, 'skipped' => 0];
        $types = [];
        $plans = [];

        foreach ($this->rooms->activeTypes($property) as $type) {
            $types[strtolower($type->code)] = $type->id;
        }

        foreach ($this->plans->activePlans($property) as $plan) {
            $plans[strtolower($plan->code)] = $plan->id;
        }

        $report = $this->batch->run($parsed, function (array $row) use ($property, $actorId, $types, $plans, &$summary): void {
            $channel = strtolower($row['channel']);
            $ref = $row['external_ref'];

            if (preg_match('/^[a-z0-9_]{2,30}$/D', $channel) !== 1) {
                throw Refusal::invalid('The channel is 2 to 30 letters, digits or underscores, for example booking_com.', ['channel']);
            }

            if (preg_match('/^[A-Za-z0-9._\/-]{3,60}$/D', $ref) !== 1) {
                throw Refusal::invalid('The booking code of the channel is 3 to 60 letters, digits or . _ / -.', ['external_ref']);
            }

            $status = strtolower($row['status'] ?? '') === '' ? 'confirmed' : strtolower($row['status']);

            if (! in_array($status, ['confirmed', 'tentative', 'cancelled'], true)) {
                throw Refusal::invalid('The status is confirmed, tentative or cancelled.', ['status']);
            }

            $existing = $this->refs->reservationOf($property, $channel, $ref);

            if ($status === 'cancelled') {
                $summary[$this->cancel($property, $actorId, $existing, $channel) ? 'cancelled' : 'skipped']++;

                return;
            }

            if ($existing !== null) {
                $summary['skipped']++;

                return;
            }

            $summary['created'] += $this->create($property, $actorId, $row, $channel, $ref, $status, $types, $plans);
        }, $dryRun);

        return [...$report, 'summary' => $summary];
    }

    /** @return bool whether a booking was cancelled; false when there was nothing to cancel */
    private function cancel(PropertyId $property, string $actorId, ?string $reservationId, string $channel): bool
    {
        if ($reservationId === null) {
            return false;
        }

        $reservation = $this->reservations->find($property, $actorId, $reservationId);

        if ($reservation->status === ReservationStatus::Cancelled) {
            return false;
        }

        if (! in_array($reservation->status, [ReservationStatus::Tentative, ReservationStatus::Confirmed, ReservationStatus::Guaranteed], true)) {
            throw Refusal::stateConflict("{$reservation->number} is not a booking that can be cancelled any more.");
        }

        $this->reservations->cancel($property, $actorId, $reservation->id, 'Cancelled by the channel '.$channel, $reservation->lockVersion);

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $types
     * @param  array<string, string>  $plans
     * @return int 1 when the booking was made
     */
    private function create(PropertyId $property, string $actorId, array $row, string $channel, string $ref, string $status, array $types, array $plans): int
    {
        $type = $types[strtolower($row['room_type_code'])] ?? throw Refusal::invalid('This room type code is not one of the hotel\'s active room types.', ['room_type_code']);
        $plan = $plans[strtolower($row['rate_plan_code'])] ?? throw Refusal::invalid('This rate plan code is not one of the hotel\'s active rate plans.', ['rate_plan_code']);
        $adults = $this->count($row['adults'], 1, 'adults');
        $children = $this->count($row['children'] ?? '', 0, 'children');
        $total = $row['channel_total'] ?? '';

        if ($total !== '' && preg_match('/^\d{1,13}([.,]\d{1,2})?$/D', $total) !== 1) {
            throw Refusal::invalid('The channel\'s total is a plain number, for example 1450000.', ['channel_total']);
        }

        $notes = trim($row['notes'] ?? '');

        if (mb_strlen($notes) > 200) {
            throw Refusal::invalid('The notes are at most 200 characters.', ['notes']);
        }

        $note = trim("[{$channel} {$ref}]".($total === '' ? '' : " channel total {$total}").($notes === '' ? '' : " {$notes}"));
        $key = IdempotencyKey::fromString('channel-import-'.substr(hash('sha256', $property->toString().'|'.$channel.'|'.$ref), 0, 48));

        try {
            $reservation = $this->reservations->create(
                $property,
                $actorId,
                new ReservationRequest('ota', $row['guest_name'], ($row['phone'] ?? '') === '' ? null : $row['phone'], ($row['email'] ?? '') === '' ? null : $row['email'], $row['arrival'], $row['departure'], $adults, $children, $type, $plan, $note, $status),
                $key,
            );
        } catch (BookingRefused $e) {
            throw Refusal::stateConflict($e->getMessage());
        }

        $this->refs->add($property, $channel, $ref, $reservation->id, $actorId, $this->clock->nowUtc());

        return 1;
    }

    private function count(string $value, int $minimum, string $field): int
    {
        if ($value === '' && $minimum === 0) {
            return 0;
        }

        if (preg_match('/^\d{1,2}$/D', $value) !== 1 || (int) $value < $minimum || (int) $value > 40) {
            throw Refusal::invalid("The number of {$field} is a whole number from {$minimum} to 40.", [$field]);
        }

        return (int) $value;
    }
}
