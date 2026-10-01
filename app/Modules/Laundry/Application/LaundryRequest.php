<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

/**
 * A guest laundry order as housekeeping fills it in (FR-HK-020, FR-HK-021): the bag tag, the room, what is in the bag, and the
 * date and time the guest was promised (in the property's time zone).
 *
 * @phpstan-type Line array{price_item_id: string, quantity: int, brand?: ?string, condition_note?: ?string}
 */
final readonly class LaundryRequest
{
    /** @param list<array{price_item_id: string, quantity: int, brand?: ?string, condition_note?: ?string}> $lines */
    public function __construct(
        public string $barcode,
        public string $roomId,
        public bool $express,
        public string $promisedDate,
        public string $promisedTime,
        public ?string $notes,
        public array $lines,
    ) {}

    /** @return array<string, mixed> */
    public function fingerprint(): array
    {
        return ['sha256' => hash('sha256', json_encode([$this->barcode, $this->roomId, $this->express, $this->promisedDate, $this->promisedTime, $this->notes, $this->lines], JSON_THROW_ON_ERROR))];
    }
}
