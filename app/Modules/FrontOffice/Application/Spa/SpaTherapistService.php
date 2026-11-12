<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Spa;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The spa as the therapist sees it on the phone: clock in, see the bookings given to them, accept or decline one, start it, stop it, and watch what they have earned today. A treatment that is stopped
 * fixes the commission (the price and the share it was booked with) and, for a guest of the house, is charged to the folio there and then; if the folio cannot take it, the desk is left to collect it.
 */
final readonly class SpaTherapistService
{
    public function __construct(
        private SpaStore $store,
        private FolioService $folios,
        private PermissionChecker $permissions,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function mine(PropertyId $property, string $actorId): array
    {
        $therapist = $this->therapist($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $present = $this->store->attendanceOn($property, $today)[$therapist['id']] ?? null;
        $bookings = array_map(SpaView::booking(...), $this->store->bookingsOfTherapistOn($property, $therapist['id'], $today));
        $earned = 0;
        $done = 0;

        foreach ($bookings as $b) {
            if ($b['status'] === 'done') {
                $earned += $b['commission_minor'];
                $done++;
            }
        }

        // A treatment that runs now earns its share as the minutes pass: the screen shows the part earned so far.
        return [
            'therapist' => ['id' => $therapist['id'], 'name' => $therapist['name']],
            'date' => $today, 'currency' => $this->currency->currencyOf($property), 'now' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'present' => $present !== null && $present['clock_out_at'] === null, 'clock_in_at' => $present['clock_in_at'] ?? null, 'clock_out_at' => $present['clock_out_at'] ?? null,
            'bookings' => $bookings, 'treatments_done' => $done, 'commission_today_minor' => $earned,
        ];
    }

    public function clockIn(PropertyId $property, string $actorId): void
    {
        $therapist = $this->therapist($property, $actorId);
        $today = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $therapist, $today): void {
            if (! $this->store->clockIn($property, $this->ids->next(), $therapist['id'], $today, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('You are clocked in today already.', 'spa_already_in');
            }

            $this->audit->record(new AuditEntry($property->toString(), $therapist['user_id'], 'spa.clock_in', 'spa_therapist', $therapist['id'], null, ['date' => $today]));
        });
    }

    public function clockOut(PropertyId $property, string $actorId): void
    {
        $therapist = $this->therapist($property, $actorId);
        $today = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $therapist, $today): void {
            foreach ($this->store->bookingsOfTherapistOn($property, $therapist['id'], $today) as $b) {
                if ($b['status'] === 'in_progress') {
                    throw Refusal::stateConflict('Finish the treatment that is running before you clock out.', 'spa_running');
                }
            }

            if (! $this->store->clockOut($property, $therapist['id'], $today, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('You are not clocked in.', 'spa_not_in');
            }

            $this->audit->record(new AuditEntry($property->toString(), $therapist['user_id'], 'spa.clock_out', 'spa_therapist', $therapist['id'], null, ['date' => $today]));
        });
    }

    /** @return array<string, mixed> */
    public function accept(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->move($property, $actorId, $id, $lock, ['booked'], 'accepted', null, true);
    }

    /** @return array<string, mixed> */
    public function decline(PropertyId $property, string $actorId, string $id, ?string $reason, int $lock): array
    {
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 200);

        return $this->move($property, $actorId, $id, $lock, ['booked', 'accepted'], 'declined', $reason, false);
    }

    /** @return array<string, mixed> */
    public function start(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->move($property, $actorId, $id, $lock, ['accepted'], 'in_progress', null, true);
    }

    /** @return array<string, mixed> */
    public function finish(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->move($property, $actorId, $id, $lock, ['in_progress'], 'done', null, false);
    }

    /**
     * @param  list<string>  $from
     * @return array<string, mixed>
     */
    private function move(PropertyId $property, string $actorId, string $id, int $lock, array $from, string $to, ?string $reason, bool $mustBePresent): array
    {
        $therapist = $this->therapist($property, $actorId);
        $today = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $therapist, $id, $lock, $from, $to, $reason, $mustBePresent, $today): void {
            $this->store->lockBooking($property, strtolower($id));
            $b = $this->store->booking($property, strtolower($id));

            if ($b === null || $b['therapist_id'] !== $therapist['id']) {
                throw Refusal::notFound('Booking not found.');
            }

            if (! in_array($b['status'], $from, true)) {
                throw Refusal::stateConflict('This booking is not in a state that allows that.', 'spa_state');
            }

            if ($b['booking_date'] !== $today) {
                throw Refusal::stateConflict('This booking is for another day.', 'spa_other_day');
            }

            if ($mustBePresent) {
                $present = $this->store->attendanceOn($property, $today)[$therapist['id']] ?? null;

                if ($present === null || $present['clock_out_at'] !== null) {
                    throw Refusal::stateConflict('Clock in first.', 'spa_not_in');
                }
            }

            $now = $this->clock->nowUtc();
            $fields = ['status' => $to, 'reason' => $reason];

            if ($to === 'in_progress') {
                $this->store->lockRoomAndTherapist($property, $b['room_id'], $therapist['id']);

                foreach ($this->store->bookingsOn($property, $today) as $other) {
                    if ($other['status'] === 'in_progress' && ($other['room_id'] === $b['room_id'] || $other['therapist_id'] === $therapist['id'])) {
                        throw Refusal::stateConflict('A treatment is running already in this room or with you.', 'spa_busy');
                    }
                }

                $fields['started_at'] = $now;
            }

            if ($to === 'done') {
                $fields['ended_at'] = $now;
                $fields['commission_minor'] = SpaView::commission((int) $b['price_minor'], (int) $b['commission_bp']);
                $fields += $this->charge($property, $therapist['user_id'], $b, $today);
            }

            if (! $this->store->updateBooking($property, $b['id'], $lock, $fields, $now)) {
                throw Refusal::stateConflict('This booking changed after you opened it. Reload.', 'spa_stale');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), $therapist['user_id'], 'spa.'.$to, 'spa_booking', $b['id'], ['status' => $b['status']],
                ['status' => $to, 'number' => $b['number'], ...($to === 'done' ? ['commission_minor' => $fields['commission_minor'], 'charged_to_room' => isset($fields['folio_posting_id'])] : [])], $reason,
            ));
        });

        return SpaView::booking($this->store->booking($property, strtolower($id)) ?? throw Refusal::notFound('Booking not found.'));
    }

    /**
     * The price of a treatment for a guest of the house goes to the folio. When the folio cannot take it (the stay ended, the folio is closed), the treatment is still done and the desk collects it.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function charge(PropertyId $property, string $actorId, array $b, string $today): array
    {
        if ($b['reservation_id'] === null) {
            return [];
        }

        try {
            $charged = $this->folios->postGuestCharge(
                $property, $actorId, $b['reservation_id'], SpaService::chargeScope(), SpaService::CHARGE_CODE,
                mb_substr($b['treatment_name'].' · '.$b['number'], 0, 120), (int) $b['price_minor'], SpaService::SOURCE, $b['id'], SpaService::waived($b),
            );
        } catch (Refusal) {
            return [];
        }

        $p = $charged['posting'];

        return ['folio_posting_id' => $p['id'], 'service_charge_minor' => (int) $p['service_charge_minor'], 'tax_minor' => (int) $p['tax_minor'], 'total_minor' => (int) $p['total_minor'], 'paid_on' => $today];
    }

    /** @return array{id: string, name: string, user_id: string} */
    private function therapist(PropertyId $property, string $actorId): array
    {
        if (! $this->permissions->allowsInProperty($actorId, SpaService::OPERATE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not work the spa.');
        }

        $t = $this->store->therapistOfUser($property, strtolower($actorId));

        if ($t === null || ! (bool) $t['is_active']) {
            throw Refusal::notFound('This account is not a therapist of the spa.', 'spa_not_therapist');
        }

        return ['id' => $t['id'], 'name' => $t['name'], 'user_id' => strtolower($actorId)];
    }
}
