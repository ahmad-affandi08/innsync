<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Spa;

use App\Modules\FrontOffice\Application\DayUse\DayUseTimes;
use DateTimeImmutable;

/** How a spa booking is told to a screen: the same for the desk and for the therapist's phone. */
final class SpaView
{
    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function booking(array $b): array
    {
        $start = (int) $b['start_minute'];
        $startedAt = $b['started_at'];
        $paid = $b['folio_posting_id'] !== null || $b['payment_method'] !== null;

        return [
            'id' => $b['id'], 'number' => $b['number'], 'room_id' => $b['room_id'], 'room_name' => $b['room_name'], 'treatment_id' => $b['treatment_id'], 'treatment_name' => $b['treatment_name'], 'minutes' => (int) $b['minutes'],
            'therapist_id' => $b['therapist_id'], 'therapist_name' => $b['therapist_name'], 'date' => $b['booking_date'], 'start' => DayUseTimes::time($start), 'end' => DayUseTimes::time($start + (int) $b['minutes']),
            'guest_name' => $b['guest_name'], 'guest_room' => $b['guest_room'], 'reservation_id' => $b['reservation_id'], 'status' => $b['status'], 'reason' => $b['reason'],
            'price_minor' => (int) $b['price_minor'], 'service_charge_minor' => (int) $b['service_charge_minor'], 'tax_minor' => (int) $b['tax_minor'], 'total_minor' => (int) $b['total_minor'],
            'waive_service_charge' => (bool) $b['waive_service_charge'], 'waive_tax' => (bool) $b['waive_tax'],
            'payment_method' => $b['payment_method'], 'charged_to_room' => $b['folio_posting_id'] !== null, 'paid' => $paid,
            'commission_minor' => (int) $b['commission_minor'], 'commission_bp' => (int) $b['commission_bp'],
            'started_at' => $startedAt, 'ended_at' => $b['ended_at'],
            // When the running treatment is due to end, so a screen can count down without asking again.
            'ends_at' => $startedAt === null ? null : (new DateTimeImmutable((string) $startedAt))->modify('+'.(int) $b['minutes'].' minutes')->format('Y-m-d\TH:i:s\Z'),
            'lock_version' => (int) $b['lock_version'],
        ];
    }

    /** What the therapist earns on a treatment: the share set for them, rounded to the smallest unit. */
    public static function commission(int $priceMinor, int $basisPoints): int
    {
        return intdiv($priceMinor * $basisPoints + 5000, 10000);
    }
}
