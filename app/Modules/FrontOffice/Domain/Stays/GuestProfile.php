<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Domain\Stays;

use App\Shared\Domain\Time\BusinessDate;

/**
 * What the registration form records about a guest (FR-FO-010): the person, their identity document and where they live.
 * The identity details are personal data; the Infrastructure layer stores them encrypted and nothing here logs them.
 */
final readonly class GuestProfile
{
    public string $fullName;

    public string $nationality;

    public string $idNumber;

    public ?string $visaNumber;

    public string $address;

    public function __construct(
        public string $id,
        string $fullName,
        string $nationality,
        public IdType $idType,
        string $idNumber,
        public ?BusinessDate $idValidUntil,
        ?string $visaNumber,
        string $address,
    ) {
        $this->fullName = trim($fullName);
        $this->nationality = strtoupper(trim($nationality));
        $this->idNumber = trim($idNumber);
        $visa = $visaNumber === null ? null : trim($visaNumber);
        $this->visaNumber = $visa === '' ? null : $visa;
        $this->address = trim($address);

        if ($this->fullName === '' || mb_strlen($this->fullName) > 150) {
            throw StayRuleViolation::invalidGuest('The guest name is required, at most 150 characters.', 'full_name');
        }

        if (preg_match('/^[A-Z]{2}$/D', $this->nationality) !== 1) {
            throw StayRuleViolation::invalidGuest('Nationality is a two-letter ISO country code, for example ID.', 'nationality');
        }

        if ($this->idNumber === '' || mb_strlen($this->idNumber) > 40) {
            throw StayRuleViolation::invalidGuest('The identity number is required, at most 40 characters.', 'id_number');
        }

        // A KTP carries a 16-digit NIK.
        if ($idType === IdType::Ktp && preg_match('/^[0-9]{16}$/D', $this->idNumber) !== 1) {
            throw StayRuleViolation::invalidGuest('A KTP number has exactly 16 digits.', 'id_number');
        }

        if ($this->visaNumber !== null && mb_strlen($this->visaNumber) > 40) {
            throw StayRuleViolation::invalidGuest('The visa number is at most 40 characters.', 'visa_number');
        }

        if ($this->address === '' || mb_strlen($this->address) > 500) {
            throw StayRuleViolation::invalidGuest('The home address is required, at most 500 characters.', 'address');
        }
    }

    /** The form a number is compared in: no spaces, hyphens or dots, and no letter case. */
    public static function normalizeNumber(string $number): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/u', '', $number));
    }

    public function normalizedNumber(): string
    {
        return self::normalizeNumber($this->idNumber);
    }

    /** Only the last four characters, for screens of people who may not see the whole number. */
    public static function mask(string $number): string
    {
        $length = mb_strlen($number);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', $length - 4).mb_substr($number, -4);
    }

    /**
     * Warnings about the document, never refusals: the front desk decides (FR-FO-014). A missing validity date is not guessed.
     *
     * @return list<string>
     */
    public function documentWarnings(BusinessDate $today, BusinessDate $departure): array
    {
        $warnings = [];

        if ($this->idValidUntil !== null) {
            if ($this->idValidUntil->isBefore($today)) {
                $warnings[] = 'id_expired';
            } elseif ($this->idValidUntil->isBefore($departure)) {
                $warnings[] = 'id_expires_during_stay';
            }
        }

        if ($this->nationality !== 'ID' && $this->visaNumber === null && $this->idType !== IdType::Kitas) {
            $warnings[] = 'visa_missing';
        }

        return $warnings;
    }
}
