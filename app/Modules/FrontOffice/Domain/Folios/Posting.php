<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Folios;

use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/**
 * One immutable line of a folio ledger (BR-003). Charges add to the balance, payments subtract. A correction is a new
 * `reversal` that mirrors the original exactly; a posting can be reversed once and a reversal can never be reversed.
 */
final readonly class Posting
{
    /** @param array<string, mixed>|null $schemeSnapshot */
    private function __construct(
        public string $id,
        public EntryType $type,
        public string $code,
        public string $description,
        public Money $base,
        public Money $serviceCharge,
        public Money $tax,
        public Money $total,
        public BusinessDate $businessDate,
        public DateTimeImmutable $postedAt,
        public ?string $postedBy,
        public string $source,
        public ?string $sourceRef,
        public ?string $reversesId,
        public ?string $reason,
        public ?PaymentMethod $method,
        public ?string $methodReference,
        public ?string $purpose,
        public ?string $approvalId,
        public ?array $schemeSnapshot,
        public int $seq = 0,
    ) {}

    /** @param array<string, mixed>|null $schemeSnapshot */
    public static function charge(string $id, string $code, string $description, Money $base, Money $serviceCharge, Money $tax, BusinessDate $date, DateTimeImmutable $at, ?string $by, string $source, ?string $sourceRef, ?array $schemeSnapshot = null): self
    {
        self::assertCode($code);
        self::assertDescription($description);

        if ($base->isNegative() || $serviceCharge->isNegative() || $tax->isNegative()) {
            throw FolioRuleViolation::invalid('A charge has no negative parts; correct a charge with a reversal.');
        }

        $total = $base->add($serviceCharge)->add($tax);

        if ($total->amountMinor <= 0) {
            throw FolioRuleViolation::invalid('A charge must be more than zero.');
        }

        return new self($id, EntryType::Charge, $code, trim($description), $base, $serviceCharge, $tax, $total, $date, $at, $by, self::source($source), self::ref($sourceRef), null, null, null, null, null, null, $schemeSnapshot);
    }

    /**
     * The charge that takes the place of a charge moved to another folio (FR-FO-023): the same parts, the same code, dated today, with
     * the reason. The folio it leaves gets the reversal of the original; together they leave the money where the guest wants it.
     */
    public static function movedFrom(self $original, string $id, string $fromFolioNumber, string $reason, BusinessDate $date, DateTimeImmutable $at, ?string $by): self
    {
        if ($original->type !== EntryType::Charge) {
            throw FolioRuleViolation::notReversible('Only a charge can be moved to another folio.');
        }

        return new self(
            $id, EntryType::Charge, $original->code, mb_substr('Moved from '.$fromFolioNumber.': '.$original->description, 0, 200), $original->base, $original->serviceCharge, $original->tax, $original->total,
            $date, $at, $by, 'transfer', 'transfer:'.$original->id, null, self::reasonText($reason), null, null, null, null, $original->schemeSnapshot,
        );
    }

    public static function payment(string $id, string $code, string $description, Money $amount, PaymentMethod $method, ?string $reference, string $purpose, BusinessDate $date, DateTimeImmutable $at, ?string $by, string $source, ?string $sourceRef): self
    {
        self::assertCode($code);
        self::assertDescription($description);

        if ($amount->amountMinor <= 0) {
            throw FolioRuleViolation::invalid('A payment must be more than zero.');
        }

        if (! in_array($purpose, ['deposit', 'settlement'], true)) {
            throw FolioRuleViolation::invalid('A payment is a deposit or a settlement.');
        }

        $zero = Money::zero($amount->currency);

        return new self($id, EntryType::Payment, $code, trim($description), $zero, $zero, $zero, $amount->negate(), $date, $at, $by, self::source($source), self::ref($sourceRef), null, null, $method, self::reference($method, $reference), $purpose, null, null);
    }

    public static function refund(string $id, string $code, string $description, Money $amount, PaymentMethod $method, ?string $reference, string $reason, BusinessDate $date, DateTimeImmutable $at, ?string $by, ?string $approvalId): self
    {
        self::assertCode($code);
        self::assertDescription($description);

        if ($amount->amountMinor <= 0) {
            throw FolioRuleViolation::invalid('A refund must be more than zero.');
        }

        $zero = Money::zero($amount->currency);

        return new self($id, EntryType::Refund, $code, trim($description), $zero, $zero, $zero, $amount, $date, $at, $by, 'front_office', null, null, self::reasonText($reason), $method, self::reference($method, $reference), null, $approvalId, null);
    }

    /** The exact opposite of `$original`, for the same business reason it was wrong. */
    public static function reversalOf(self $original, string $id, string $reason, BusinessDate $date, DateTimeImmutable $at, ?string $by, ?string $approvalId): self
    {
        if ($original->type === EntryType::Reversal) {
            throw FolioRuleViolation::notReversible('A reversal cannot be reversed; post a new charge or payment instead.');
        }

        return new self(
            $id, EntryType::Reversal, $original->code, 'Reversal of '.$original->description, $original->base->negate(), $original->serviceCharge->negate(), $original->tax->negate(), $original->total->negate(),
            $date, $at, $by, 'front_office', null, $original->id, self::reasonText($reason), $original->method, $original->methodReference, null, $approvalId, null,
        );
    }

    /** Whether undoing this posting moves money (a payment or a refund), which makes its correction a sensitive one. */
    public function movesMoney(): bool
    {
        return $this->type === EntryType::Payment || $this->type === EntryType::Refund
            || ($this->type === EntryType::Reversal && $this->method !== null);
    }

    public function isReversal(): bool
    {
        return $this->type === EntryType::Reversal;
    }

    /**
     * Rebuilds a posting from the ledger. No rule is re-checked: the rows were validated when they were written and the
     * database refuses any change to them.
     *
     * @param  array<string, mixed>|null  $schemeSnapshot
     */
    public static function restore(
        string $id, EntryType $type, string $code, string $description, Money $base, Money $serviceCharge, Money $tax, Money $total, BusinessDate $businessDate, DateTimeImmutable $postedAt,
        ?string $postedBy, string $source, ?string $sourceRef, ?string $reversesId, ?string $reason, ?PaymentMethod $method, ?string $methodReference, ?string $purpose, ?string $approvalId, ?array $schemeSnapshot, int $seq,
    ): self {
        return new self($id, $type, $code, $description, $base, $serviceCharge, $tax, $total, $businessDate, $postedAt, $postedBy, $source, $sourceRef, $reversesId, $reason, $method, $methodReference, $purpose, $approvalId, $schemeSnapshot, $seq);
    }

    /** Same posting with the sequence number the ledger gave it. */
    public function withSeq(int $seq): self
    {
        return new self(
            $this->id, $this->type, $this->code, $this->description, $this->base, $this->serviceCharge, $this->tax, $this->total, $this->businessDate, $this->postedAt, $this->postedBy,
            $this->source, $this->sourceRef, $this->reversesId, $this->reason, $this->method, $this->methodReference, $this->purpose, $this->approvalId, $this->schemeSnapshot, $seq,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seq' => $this->seq,
            'type' => $this->type->value,
            'code' => $this->code,
            'description' => $this->description,
            'base_minor' => $this->base->amountMinor,
            'service_charge_minor' => $this->serviceCharge->amountMinor,
            'tax_minor' => $this->tax->amountMinor,
            'total_minor' => $this->total->amountMinor,
            'currency' => $this->total->currency,
            'business_date' => $this->businessDate->toString(),
            'posted_at' => $this->postedAt->format('Y-m-d\TH:i:s\Z'),
            'source' => $this->source,
            'reverses_id' => $this->reversesId,
            'reason' => $this->reason,
            'payment_method' => $this->method?->value,
            'payment_reference' => $this->methodReference,
            'payment_purpose' => $this->purpose,
        ];
    }

    private static function assertCode(string $code): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{1,19}$/D', $code) !== 1) {
            throw FolioRuleViolation::invalid('A charge or payment code is 2 to 20 uppercase letters, digits or underscores.');
        }
    }

    private static function assertDescription(string $description): void
    {
        if (trim($description) === '' || mb_strlen($description) > 200) {
            throw FolioRuleViolation::invalid('A description of at most 200 characters is required.');
        }
    }

    private static function reasonText(string $reason): string
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw FolioRuleViolation::invalid('A reason of at most 500 characters is required.');
        }

        return trim($reason);
    }

    private static function reference(PaymentMethod $method, ?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        if ($method->needsReference() && ($reference === null || $reference === '')) {
            throw FolioRuleViolation::invalid('This payment method needs the reference from the slip, the receipt or the transfer.');
        }

        if ($reference !== null && preg_match('/^[A-Za-z0-9 ._\/#:-]{1,80}$/D', $reference) !== 1) {
            throw FolioRuleViolation::invalid('A payment reference is up to 80 letters, digits and simple punctuation. Never enter a card number.');
        }

        // A card number is 13 to 19 digits; refuse anything that looks like one even inside a longer text (NFR-09).
        if ($reference !== null && preg_match('/(?:\d[ -]?){13,19}/', $reference) === 1) {
            throw FolioRuleViolation::invalid('This looks like a card number. Enter only the slip or approval reference.');
        }

        return $reference === '' ? null : $reference;
    }

    private static function source(string $source): string
    {
        if (preg_match('/^[a-z][a-z0-9_:.-]{1,39}$/D', $source) !== 1) {
            throw FolioRuleViolation::invalid('A posting source is a short lowercase code.');
        }

        return $source;
    }

    private static function ref(?string $ref): ?string
    {
        if ($ref !== null && preg_match('/^[A-Za-z0-9_:.\/-]{1,80}$/D', $ref) !== 1) {
            throw FolioRuleViolation::invalid('A source reference is up to 80 letters, digits and simple punctuation.');
        }

        return $ref;
    }
}
