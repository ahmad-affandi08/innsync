<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Cashier;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** A cashier action was refused with a stable reason the screen can act on: `shift_required`, `shift_open`, `shift_closed`, `stale`, `drop_exceeds_cash`. */
final class CashierRefused extends RuntimeException implements ExpectedFailure
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function shiftRequired(): self
    {
        return new self('shift_required', 'Open your cashier shift before taking or returning money.');
    }

    public static function alreadyOpen(): self
    {
        return new self('shift_open', 'This person already has an open cashier shift. Close it first.');
    }

    public static function closed(): self
    {
        return new self('shift_closed', 'This shift is already closed.');
    }

    public static function stale(): self
    {
        return new self('stale', 'This shift changed after you opened it. Refresh and try again.');
    }

    public static function dropExceedsCash(int $available): self
    {
        return new self('drop_exceeds_cash', "A drop cannot be more than the cash in the drawer ({$available}).");
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
        return 'conflict_cashier';
    }

    public function conflict(): ?array
    {
        return ['reason' => $this->reason, 'action' => 'refresh'];
    }

    public function invalidFields(): array
    {
        return [];
    }
}
