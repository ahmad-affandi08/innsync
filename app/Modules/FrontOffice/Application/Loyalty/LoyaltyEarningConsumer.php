<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Loyalty;

use App\Modules\FrontOffice\Application\GuestNotes\GuestKey;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;

/**
 * Gives a guest the points of a stay when it ends: one point for every block of room revenue charged to the stay (before service charge and tax), once per reservation. Nothing is given while the programme is
 * off, to a guest with no phone number (the name alone cannot tell two guests apart), or when no room revenue was charged.
 */
final readonly class LoyaltyEarningConsumer implements OutboxConsumer
{
    public function __construct(private LoyaltyStore $store, private ReservationRepository $reservations, private AuditTrail $audit, private IdentifierGenerator $ids, private PropertyContext $context, private Clock $clock) {}

    public function name(): string
    {
        return 'frontoffice.loyalty';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === 'frontoffice.stay.checked_out';
    }

    public function consume(OutboxMessage $message): void
    {
        $rules = LoyaltyService::rules();

        if (! $rules['enabled']) {
            return;
        }

        $this->context->run($message->event->propertyId, function () use ($message, $rules): void {
            $property = $message->event->propertyId;
            $d = $message->event->data;
            $reservationId = (string) ($d['reservation_id'] ?? '');
            $reservation = $this->reservations->find($property, $reservationId);
            $digits = preg_replace('/\D/', '', (string) $reservation?->guestPhone);

            if ($reservation === null || $digits === '') {
                return;
            }

            $points = intdiv($this->store->roomBaseOf($property, $reservationId), $rules['earn_per_point_minor']);

            if ($points < 1 || $this->store->entryBySource($property, 'earn', 'stay', $reservationId) !== null) {
                return;
            }

            $now = $this->clock->nowUtc();
            $guestKey = GuestKey::of($reservation->guestName, $reservation->guestPhone);
            $accountId = $this->store->ensureAccount($property, $this->ids->next(), $guestKey, $reservation->guestName, substr($digits, -4), $now);
            // The tier the guest had before this stay earns a bonus on it.
            $base = $points;
            $points = LoyaltyTiers::withBonus($points, (int) ($this->store->accountOfGuest($property, $guestKey)['earned_points'] ?? 0), $rules['tiers']);

            if (! $this->store->addEntry($property, ['id' => $this->ids->next(), 'account_id' => $accountId, 'kind' => 'earn', 'points' => $points, 'balance_after' => $this->store->balance($property, $accountId) + $points, 'source_type' => 'stay', 'source_ref' => $reservationId, 'note' => $reservation->number.($points > $base ? ' (+'.($points - $base).' tier bonus)' : ''), 'business_date' => (string) $d['business_date'], 'actor_id' => null], $now)) {
                return;
            }

            $this->store->move($property, $accountId, $points, $now);
            $this->audit->record(new AuditEntry($property->toString(), null, 'loyalty.earned', 'loyalty_account', $accountId, null, ['reservation' => $reservation->number, 'points' => $points]));
        });
    }
}
