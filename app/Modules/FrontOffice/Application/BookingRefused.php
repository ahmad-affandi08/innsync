<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/**
 * A booking could not be made, with a stable reason the screen can act on (`no_availability`, `oversell_warning`,
 * `not_bookable`, `arrival_in_the_past`, `beyond_horizon`, `occupancy_exceeded`). Rendered as a standard error.
 */
final class BookingRefused extends RuntimeException implements ExpectedFailure
{
    private function __construct(public readonly string $reason, private readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }

    public static function noAvailability(string $firstNight): self
    {
        return new self('no_availability', 409, "No room of this type is left on {$firstNight}.");
    }

    /** @param list<string> $nights */
    public static function oversellWarning(array $nights): self
    {
        return new self('oversell_warning', 409, 'This booking would oversell the room type on '.implode(', ', array_slice($nights, 0, 5)).'.');
    }

    /** @param list<string> $codes */
    public static function notBookable(array $codes): self
    {
        return new self('not_bookable', 422, 'This stay cannot be sold: '.implode(', ', array_slice($codes, 0, 8)).'.');
    }

    public static function arrivalInThePast(): self
    {
        return new self('arrival_in_the_past', 422, 'Arrival cannot be before the current business date.');
    }

    public static function beyondHorizon(int $days): self
    {
        return new self('beyond_horizon', 422, "Arrival is further ahead than the {$days}-day sales horizon.");
    }

    public static function occupancyExceeded(): self
    {
        return new self('occupancy_exceeded', 422, 'The guests exceed what this room type allows.');
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->httpStatus === 409 ? 'conflict' : 'validation_failed';
    }

    public function messageKey(): string
    {
        return $this->httpStatus === 409 ? 'conflict_booking' : 'validation_failed';
    }

    public function conflict(): ?array
    {
        return $this->httpStatus === 409 ? ['reason' => $this->reason, 'action' => 'review'] : null;
    }

    public function invalidFields(): array
    {
        return match ($this->reason) {
            'arrival_in_the_past', 'beyond_horizon' => ['arrival'],
            'occupancy_exceeded' => ['adults', 'children'],
            default => [],
        };
    }
}
