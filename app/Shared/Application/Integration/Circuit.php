<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use DateTimeImmutable;

/** Circuit breaker state for one provider in one property. Immutable; `version` makes updates compare-and-set. */
final readonly class Circuit
{
    public function __construct(
        public int $consecutiveFailures,
        public ?DateTimeImmutable $openedAt,
        public ?DateTimeImmutable $trialStartedAt,
        public int $version,
    ) {}

    public static function closed(): self
    {
        return new self(0, null, null, 0);
    }

    public function isOpen(): bool
    {
        return $this->openedAt !== null;
    }

    /** Whether a call may go out now. Open circuits admit one trial per window after the open period passed. */
    public function admits(DateTimeImmutable $now, int $openSeconds): bool
    {
        if ($this->openedAt === null) {
            return true;
        }

        $reopensAt = ($this->trialStartedAt ?? $this->openedAt)->modify("+{$openSeconds} seconds");

        return $now >= $reopensAt;
    }

    public function withTrial(DateTimeImmutable $now): self
    {
        return new self($this->consecutiveFailures, $this->openedAt, $now, $this->version);
    }

    public function afterSuccess(): self
    {
        return new self(0, null, null, $this->version);
    }

    public function afterFailure(DateTimeImmutable $now, int $threshold): self
    {
        $failures = $this->consecutiveFailures + 1;

        // A failed trial reopens immediately; otherwise open once the threshold is reached.
        if ($this->openedAt !== null || $failures >= $threshold) {
            return new self($failures, $now, null, $this->version);
        }

        return new self($failures, null, null, $this->version);
    }
}
