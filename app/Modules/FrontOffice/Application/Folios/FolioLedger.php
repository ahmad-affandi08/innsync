<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Folios;

use App\Modules\FrontOffice\Domain\Folios\Folio;
use App\Modules\FrontOffice\Domain\Folios\FolioRuleViolation;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/**
 * The write side of the folio ledger, with no permission checks: callers inside Front Office (the permissioned
 * `FolioService`, night audit) and contexts that post charges through a contract authorize their own use first.
 *
 * Every posting runs in one transaction under the folio's row lock, takes the next sequence number, and moves the stored
 * balance. A fact that carries a source and a source reference (a POS bill, a night audit run) is posted once however many
 * times it is delivered (BR-005). Nothing is ever updated or deleted (BR-003).
 */
final readonly class FolioLedger
{
    public function __construct(
        private FolioRepository $folios,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * Posts `$build($businessDate, $now)` to the folio. If a posting with the same source and reference exists it is returned
     * and nothing is written; if it exists for different content the call is refused.
     *
     * @param  \Closure(BusinessDate, \DateTimeImmutable): Posting  $build
     * @return array{posting: Posting, replayed: bool, balance: Money}
     */
    public function post(PropertyId $property, string $folioId, \Closure $build): array
    {
        $this->assertProperty($property);

        try {
            return $this->transactions->run(function () use ($property, $folioId, $build): array {
                $folio = $this->folios->lock($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
                $posting = $build($this->businessDate->current($property), $this->clock->nowUtc());

                if ($posting->sourceRef !== null) {
                    $existing = $this->folios->findBySource($property, $posting->source, $posting->sourceRef);

                    if ($existing !== null) {
                        if ($existing[0] !== $folio->id || $existing[1]->total->amountMinor !== $posting->total->amountMinor || $existing[1]->code !== $posting->code) {
                            throw Refusal::stateConflict('This source reference was already posted with different content.');
                        }

                        return ['posting' => $existing[1], 'replayed' => true, 'balance' => $folio->balance];
                    }
                }

                $folio->assertOpen();
                $folio->assertCurrency($posting->total);
                $saved = $this->folios->append($property, $folio, $posting);

                return ['posting' => $saved, 'replayed' => false, 'balance' => $folio->balance->add($posting->total)];
            });
        } catch (FolioRuleViolation $violation) {
            throw $violation->reasonCode === FolioRuleViolation::CLOSED || $violation->reasonCode === FolioRuleViolation::BALANCE_NOT_ZERO
                ? Refusal::stateConflict($violation->getMessage())
                : Refusal::invalid($violation->getMessage(), ['code', 'description', 'amount', 'payment_reference', 'reason']);
        }
    }

    public function newId(): string
    {
        return $this->ids->next();
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
