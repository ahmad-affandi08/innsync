<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\NightAudit;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** Night audit could not run now, with a stable reason: `too_early`, `blocked` (gates without a waiver), or `already_closed`. */
final class NightAuditRefused extends RuntimeException implements ExpectedFailure
{
    /** @param list<string> $gates */
    private function __construct(public readonly string $reason, string $message, public readonly array $gates = [])
    {
        parent::__construct($message);
    }

    public static function tooEarly(string $date, string $earliest): self
    {
        return new self('too_early', "Night audit for {$date} can start from {$earliest} (property time).");
    }

    /** @param list<string> $gates */
    public static function blocked(array $gates): self
    {
        return new self('blocked', 'Night audit is blocked by: '.implode(', ', $gates).'. Resolve them or waive them with a reason.', $gates);
    }

    public static function alreadyClosed(string $date): self
    {
        return new self('already_closed', "The business date {$date} is already closed.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'conflict';
    }

    public function messageKey(): string
    {
        return 'conflict_night_audit';
    }

    public function conflict(): ?array
    {
        return ['reason' => $this->reason, 'action' => 'review'];
    }

    public function invalidFields(): array
    {
        return [];
    }
}
