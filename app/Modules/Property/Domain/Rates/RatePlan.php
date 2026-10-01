<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Rates;

use InvalidArgumentException;

/**
 * A way of selling rooms: its own prices per room type and date (`RatePeriod`), restrictions (`RateRestriction`), what is
 * included, and whether quoted prices already include service charge and tax ("nett") or not ("++").
 */
final readonly class RatePlan
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public RateKind $kind,
        public ?string $inclusions,
        public bool $pricesIncludeCharges,
        public bool $isActive,
        public int $lockVersion,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9-]{1,19}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A rate plan code is 2 to 20 uppercase letters, digits or hyphens, starting with a letter.');
        }

        if (trim($name) === '' || mb_strlen($name) > 100 || ($inclusions !== null && mb_strlen($inclusions) > 500)) {
            throw new InvalidArgumentException('A rate plan needs a name of at most 100 characters and inclusions of at most 500.');
        }
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function revised(string $name, RateKind $kind, ?string $inclusions, bool $pricesIncludeCharges): self
    {
        return new self($this->id, $this->code, trim($name), $kind, $inclusions === null || trim($inclusions) === '' ? null : trim($inclusions), $pricesIncludeCharges, $this->isActive, $this->lockVersion);
    }

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->code, $this->name, $this->kind, $this->inclusions, $this->pricesIncludeCharges, $active, $this->lockVersion);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'inclusions' => $this->inclusions,
            'prices_include_charges' => $this->pricesIncludeCharges,
            'is_active' => $this->isActive,
            'lock_version' => $this->lockVersion,
        ];
    }
}
