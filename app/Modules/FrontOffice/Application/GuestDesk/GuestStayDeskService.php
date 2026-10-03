<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Modules\FrontOffice\Application\Feedback\FeedbackService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Front office for the guest self-service (see `GuestStayDesk`). Every step is the service a receptionist uses, called as the guest self-service account, which holds only the rights to take a request, take a
 * complaint and read a folio; it cannot post, reverse or refund anything. A request is routed to its department as any request is; a complaint is taken as medium and the staff re-rate it.
 */
final readonly class GuestStayDeskService implements GuestStayDesk
{
    public function __construct(
        private GuestRequestService $requests,
        private FeedbackService $feedback,
        private FolioService $folios,
        private StayRepository $stays,
        private ReservationRepository $reservations,
        private SystemActors $actors,
    ) {}

    public function openRequest(PropertyId $property, string $stayId, string $category, string $title, ?string $detail, string $clientKey): array
    {
        $r = $this->requests->open($property, $this->actor($property), $stayId, $category, $title, $detail, false, 'guest-'.$clientKey, null);

        return ['id' => (string) $r['id'], 'number' => (string) $r['number']];
    }

    public function recordComplaint(PropertyId $property, string $stayId, string $summary, ?string $detail, string $clientKey): array
    {
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        if (! $stay->isInHouse()) {
            throw Refusal::stateConflict('Only a guest who is in the house can send this.');
        }

        $f = $this->feedback->record($property, $this->actor($property), 'complaint', 'medium', 'online', $stay->reservationId, null, $summary, $detail, null, 'guest-'.$clientKey);

        return ['id' => (string) $f['id'], 'number' => (string) $f['number']];
    }

    public function statusOf(PropertyId $property, string $stayId, string $kind, string $id): ?array
    {
        $actor = $this->actor($property);

        if ($kind === 'request') {
            foreach ($this->requests->queue($property, $actor, null, null, null, strtolower($stayId))['requests'] as $r) {
                if ($r['id'] === strtolower($id)) {
                    return ['number' => (string) $r['number'], 'status' => (string) $r['status'], 'resolution' => $r['resolution'] ?? null];
                }
            }

            return null;
        }

        try {
            $f = $this->feedback->view($property, $actor, strtolower($id))['item'];
        } catch (Refusal) {
            return null;
        }

        $stay = $this->stays->find($property, strtolower($stayId));

        if ($stay === null || ($f['reservation_id'] ?? null) !== $stay->reservationId) {
            return null;
        }

        return ['number' => (string) $f['number'], 'status' => match ($f['status']) {
            'open' => 'open', 'in_progress' => 'in_progress', default => 'done',
        }, 'resolution' => $f['resolution'] ?? null];
    }

    public function runningBill(PropertyId $property, string $stayId): ?array
    {
        $stay = $this->stays->find($property, strtolower($stayId));

        if ($stay === null) {
            return null;
        }

        $actor = $this->actor($property);
        $outlets = [];
        $payments = [];
        $total = 0;
        $paid = 0;
        $currency = 'IDR';

        foreach ($this->folios->forReservation($property, $actor, $stay->reservationId) as $folio) {
            if ((int) $folio['window'] !== 1) {
                continue;
            }

            $bill = $this->folios->bill($property, $actor, (string) $folio['id']);
            $currency = (string) $bill['currency'];

            foreach ($bill['outlets'] as $o) {
                $outlets[$o['outlet']] ??= ['outlet' => $o['outlet'], 'lines' => [], 'total_minor' => 0];

                foreach ($o['lines'] as $l) {
                    $outlets[$o['outlet']]['lines'][] = ['date' => $l['date'], 'description' => (string) $l['description'], 'total_minor' => (int) $l['total_minor']];
                }

                $outlets[$o['outlet']]['total_minor'] += (int) $o['total_minor'];
            }

            foreach ($bill['payments'] as $p) {
                $payments[] = ['date' => $p['date'], 'method' => (string) $p['method'], 'amount_minor' => (int) $p['amount_minor']];
            }

            $total += (int) $bill['totals']['total'];
            $paid += (int) $bill['totals']['paid'];
        }

        return ['currency' => $currency, 'outlets' => array_values($outlets), 'payments' => $payments, 'total_minor' => $total, 'paid_minor' => $paid, 'balance_minor' => $total - $paid];
    }

    public function stay(PropertyId $property, string $stayId): ?array
    {
        $stay = $this->stays->find($property, strtolower($stayId));

        if ($stay === null) {
            return null;
        }

        $reservation = $this->reservations->find($property, $stay->reservationId);

        return ['in_house' => $stay->isInHouse(), 'expected_departure' => $stay->expectedDeparture->toString(), 'reservation_id' => $stay->reservationId, 'room_id' => $stay->roomId, 'guest_name' => $reservation?->guestName];
    }

    private function actor(PropertyId $property): string
    {
        return $this->actors->guestSelfService($property, [GuestRequestService::MANAGE_PERMISSION, FeedbackService::MANAGE_PERMISSION, FolioService::VIEW_PERMISSION, GuestRequestService::VIEW_PERMISSION, FeedbackService::VIEW_PERMISSION]);
    }
}
