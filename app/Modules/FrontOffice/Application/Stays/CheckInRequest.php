<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

/** The registration form of a check-in (FR-FO-010), as the front desk filled it. */
final readonly class CheckInRequest
{
    public function __construct(
        public string $reservationId,
        public string $roomId,
        public string $fullName,
        public string $nationality,
        public string $idType,
        public string $idNumber,
        public ?string $idValidUntil,
        public ?string $visaNumber,
        public string $address,
        public int $adults,
        public int $children,
    ) {}

    /**
     * Identifies the request for idempotency without keeping the identity number readable.
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return ['sha256' => hash('sha256', json_encode([
            $this->reservationId, $this->roomId, $this->fullName, $this->nationality, $this->idType, $this->idNumber,
            $this->idValidUntil, $this->visaNumber, $this->address, $this->adults, $this->children,
        ], JSON_THROW_ON_ERROR))];
    }
}
