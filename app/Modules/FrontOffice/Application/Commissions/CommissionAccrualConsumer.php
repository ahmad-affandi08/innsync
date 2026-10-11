<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Commissions;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;

/**
 * Accrues the commission of a stay when the guest checks out: the rate of the partner the reservation came through (the company it is linked to, else the channel it was imported from) on the room revenue
 * charged to its folios. Once per reservation; nothing is accrued for a partner without a rate or one that is switched off, or when no room revenue was charged.
 */
final readonly class CommissionAccrualConsumer implements OutboxConsumer
{
    public function __construct(private CommissionStore $store, private ReservationRepository $reservations, private AuditTrail $audit, private IdentifierGenerator $ids, private PropertyContext $context, private Clock $clock) {}

    public function name(): string
    {
        return 'frontoffice.commissions';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === 'frontoffice.stay.checked_out';
    }

    public function consume(OutboxMessage $message): void
    {
        $this->context->run($message->event->propertyId, function () use ($message): void {
            $property = $message->event->propertyId;
            $d = $message->event->data;
            $reservationId = (string) ($d['reservation_id'] ?? '');
            $key = $this->store->partnerKeyOf($property, $reservationId);
            $partner = $key === null ? null : $this->store->partnerOf($property, $key[0], $key[1]);

            if ($partner === null || ! $partner['is_active']) {
                return;
            }

            $base = $this->store->roomBaseOf($property, $reservationId);

            if ($base <= 0) {
                return;
            }

            $commission = intdiv($base * (int) $partner['rate_bp'] + 5000, 10_000);
            $number = $this->reservations->find($property, $reservationId)?->number ?? $reservationId;
            $id = $this->ids->next();

            if ($this->store->addAccrual($property, ['id' => $id, 'partner_id' => $partner['id'], 'reservation_id' => $reservationId, 'reservation_number' => $number, 'stay_out_on' => (string) $d['business_date'], 'base_minor' => $base, 'rate_bp' => (int) $partner['rate_bp'], 'commission_minor' => $commission], $this->clock->nowUtc())) {
                $this->audit->record(new AuditEntry($property->toString(), null, 'commission.accrued', 'commission_accrual', $id, null, ['partner' => $partner['name'], 'reservation' => $number, 'base_minor' => $base, 'rate_bp' => (int) $partner['rate_bp'], 'commission_minor' => $commission]));
            }
        });
    }
}
