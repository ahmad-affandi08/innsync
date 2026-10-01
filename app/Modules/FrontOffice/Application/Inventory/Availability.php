<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

/** One room type on one night. `available` can be negative: that is an oversold night that needs attention. */
final readonly class Availability
{
    public function __construct(
        public int $totalRooms,
        public int $blocked,
        public int $held,
        public int $sold,
        public int $overbookingAllowance,
    ) {}

    /** Rooms still sellable without overbooking. */
    public function available(): int
    {
        return $this->totalRooms - $this->blocked - $this->held - $this->sold;
    }

    public function isOversold(): bool
    {
        return $this->available() < 0;
    }

    /** Whether one more room can be sold, and if so whether it needs the overbooking allowance. */
    public function canSellOne(): bool
    {
        return $this->available() + $this->overbookingAllowance >= 1;
    }

    public function needsOverbooking(): bool
    {
        return $this->available() < 1;
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'total' => $this->totalRooms,
            'blocked' => $this->blocked,
            'held' => $this->held,
            'sold' => $this->sold,
            'available' => $this->available(),
            'allowance' => $this->overbookingAllowance,
            'oversold' => $this->isOversold(),
        ];
    }
}
