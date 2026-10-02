<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Domain;

/** One kind of garment in an order, as handed over by housekeeping. Name and price are those of the day it was made (BR-002). */
final readonly class LaundryLine
{
    public function __construct(
        public string $id,
        public string $priceItemId,
        public string $itemName,
        public ?string $brand,
        public int $quantity,
        public int $unitPriceMinor,
        public ?string $conditionNote,
        public ?int $verifiedQuantity = null,
        public ?string $treatmentName = null,
        public int $treatmentExtraMinor = 0,
        public int $expressExtraMinor = 0,
    ) {
        if ($quantity < 1 || $quantity > 999) {
            throw LaundryRuleViolation::invalid('A quantity is 1 to 999.', 'lines');
        }

        if ($brand !== null && mb_strlen($brand) > 60) {
            throw LaundryRuleViolation::invalid('A brand is at most 60 characters.', 'lines');
        }

        if ($conditionNote !== null && mb_strlen($conditionNote) > 200) {
            throw LaundryRuleViolation::invalid('A condition note is at most 200 characters.', 'lines');
        }
    }

    /** What is charged: the counted quantity once the laundry has counted it, the stated one before. */
    public function billableQuantity(): int
    {
        return $this->verifiedQuantity ?? $this->quantity;
    }

    public function withVerified(int $counted): self
    {
        return new self($this->id, $this->priceItemId, $this->itemName, $this->brand, $this->quantity, $this->unitPriceMinor, $this->conditionNote, $counted, $this->treatmentName, $this->treatmentExtraMinor, $this->expressExtraMinor);
    }

    /** The price of one piece: the item's price plus the special treatment and the express service, as they were at hand-over. */
    public function pieceMinor(): int
    {
        return $this->unitPriceMinor + $this->treatmentExtraMinor + $this->expressExtraMinor;
    }

    public function totalMinor(): int
    {
        return $this->billableQuantity() * $this->pieceMinor();
    }
}
