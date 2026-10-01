<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Folios;

use App\Modules\FrontOffice\Application\Reservations\DepositLedger;
use App\Modules\FrontOffice\Application\Reservations\PenaltyPoster;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/** The folio side of booking policies: reads the deposit a reservation holds and posts its penalty. */
final readonly class FolioPenaltyPoster implements DepositLedger, PenaltyPoster
{
    public const CODE = 'PENALTY';

    public function __construct(
        private FolioRepository $folios,
        private FolioLedger $ledger,
        private ReservationRepository $reservations,
        private DocumentNumbers $numbers,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    public function heldMinor(PropertyId $property, string $reservationId): int
    {
        return $this->folios->depositHeldMinor($property, $reservationId);
    }

    public function post(PropertyId $property, string $actorId, string $reservationId, int $amountMinor, string $description, string $sourceRef): void
    {
        if ($amountMinor <= 0) {
            return;
        }

        $reservation = $this->reservations->find($property, $reservationId) ?? throw Refusal::notFound('Reservation not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $reservation, $amountMinor, $description, $sourceRef): void {
            $folio = $this->folios->byReservation($property, $reservation->id)[0] ?? null;

            if ($folio === null) {
                // The number is taken inside the transaction so a refused attempt does not consume one.
                $folio = new Folio($this->ledger->newId(), $this->numbers->next($property, 'FOL'), $reservation->id, 1, 'Guest', $reservation->total->currency, false, Money::zero($reservation->total->currency), 0, 0);
                $this->folios->create($property, $folio, $actor, $this->clock->nowUtc());
            }

            $result = $this->ledger->post($property, $folio->id, fn (BusinessDate $date, DateTimeImmutable $at): Posting => Posting::charge(
                $this->ledger->newId(), self::CODE, mb_substr($description, 0, 200), Money::ofMinor($amountMinor, $folio->currency), Money::zero($folio->currency), Money::zero($folio->currency),
                $date, $at, $actor, 'policy', $sourceRef,
            ));

            if (! $result['replayed']) {
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'folio.penalty.posted', 'folio', $folio->id, null, ['reservation' => $reservation->number, 'amount_minor' => $amountMinor, 'currency' => $folio->currency], $description));
            }
        });
    }
}
