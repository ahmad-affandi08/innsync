<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Folios;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\Property\Application\Rates\ChargeCalculator;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/**
 * A charge found after the folio was closed (FR-FO-038): the minibar checked after the guest left, laundry handed in late. It never
 * reopens the closed folio and never changes a day that was already reported: it goes to a separate folio that points at the one it
 * belongs to, dated with the current business date, with a reason, who and when. Further late charges for the same folio use the same
 * open late folio, which is settled and closed like any other.
 */
final readonly class LateChargeService
{
    public const POST_PERMISSION = 'front-office.late-charge.post';

    public const SOURCE = 'late_charge';

    private const MAX_WINDOW = 20;

    public function __construct(
        private FolioRepository $folios,
        private FolioLedger $ledger,
        private ReservationRepository $reservations,
        private ChargeCalculator $charges,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function mayPost(PropertyId $property, string $actorId): bool
    {
        $this->assertProperty($property);

        return $this->permissions->allowsInProperty($actorId, self::POST_PERMISSION, $property);
    }

    /** @return array<string, mixed> the late folio's id and number, and the posting */
    public function post(PropertyId $property, string $actorId, string $originFolioId, string $code, string $description, int $quotedMinor, bool $pricesIncludeCharges, string $reason, ?string $sourceRef): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::POST_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not post a late charge.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        if (preg_match('/^[A-Z][A-Z0-9_]{1,19}$/D', $code) !== 1 || trim($description) === '' || mb_strlen($description) > 160) {
            throw Refusal::invalid('Give a charge code such as MINIBAR and a description of at most 160 characters.', ['code', 'description']);
        }

        if ($quotedMinor < 1) {
            throw Refusal::invalid('The price is more than zero.', ['amount']);
        }

        $origin = $this->folios->find($property, strtolower($originFolioId)) ?? throw Refusal::notFound('Folio not found.');

        if ($origin->originFolioId !== null) {
            throw Refusal::stateConflict('A late charge belongs to the original folio, not to another late charge folio.');
        }

        $actor = strtolower($actorId);
        $split = $this->charges->breakdown($property, 'rooms', $this->businessDate->current($property), $quotedMinor, $pricesIncludeCharges);

        return $this->transactions->run(function () use ($property, $actor, $origin, $code, $description, $split, $reason, $sourceRef): array {
            // A folio that is still open takes its charges the normal way; the late path is only for a closed one.
            if (! $this->folios->lock($property, $origin->id)?->isClosed) {
                throw Refusal::stateConflict('This folio is still open: post the charge to it directly.');
            }

            $late = null;

            foreach ($this->folios->lateFoliosOf($property, $origin->id) as $candidate) {
                if (! $candidate->isClosed) {
                    $late = $candidate;

                    break;
                }
            }

            if ($late === null) {
                $window = max(array_map(static fn (Folio $f): int => $f->window, $this->folios->byReservation($property, $origin->reservationId))) + 1;

                if ($window > self::MAX_WINDOW) {
                    throw Refusal::stateConflict('This reservation has no folio window left.');
                }

                $reservation = $this->reservations->find($property, $origin->reservationId) ?? throw Refusal::notFound('Reservation not found.');
                $late = new Folio($this->ledger->newId(), $this->numbers->next($property, 'FOL'), $origin->reservationId, $window, 'Late charge', $origin->currency, false, Money::zero($origin->currency), 0, 0, $origin->id);
                $this->folios->create($property, $late, $actor, $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'folio.opened', 'folio', $late->id, null, ['number' => $late->number, 'reservation_id' => $reservation->id, 'window' => $window, 'label' => 'Late charge', 'origin_folio' => $origin->number]));
            }

            $result = $this->ledger->post($property, $late->id, fn (BusinessDate $date, DateTimeImmutable $at): Posting => Posting::charge(
                $this->ledger->newId(), $code, 'Late charge ('.$origin->number.'): '.trim($description),
                Money::ofMinor($split['base_minor'], $origin->currency), Money::ofMinor($split['service_charge_minor'], $origin->currency), Money::ofMinor($split['tax_minor'], $origin->currency),
                $date, $at, $actor, self::SOURCE, $sourceRef, $split['scheme'],
            ));
            $posting = $result['posting'];

            if (! $result['replayed']) {
                $this->audit->record(new AuditEntry(
                    $property->toString(), $actor, 'folio.late_charge.posted', 'folio', $late->id, null,
                    ['origin_folio' => $origin->number, 'origin_folio_id' => $origin->id, 'code' => $code, 'total_minor' => $posting->total->amountMinor, 'currency' => $posting->total->currency, 'business_date' => $posting->businessDate->toString()],
                    $reason,
                ));
                $this->outbox->publish(new OutboxEvent($property, 'frontoffice.folio.late_charge.posted', $posting->id, 1, [
                    'folio_id' => $late->id, 'origin_folio_id' => $origin->id, 'posting_id' => $posting->id, 'total_minor' => $posting->total->amountMinor, 'currency' => $posting->total->currency, 'actor_id' => $actor,
                ]));
            }

            return ['folio_id' => $late->id, 'folio_number' => $late->number, 'posting' => $posting->toArray(), 'replayed' => $result['replayed']];
        });
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
