<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

final readonly class Findings
{
    /** @param list<Finding> $items */
    public function __construct(public array $items) {}

    public function hasFailures(): bool
    {
        return $this->count(Severity::Failure) > 0;
    }

    public function count(Severity $severity): int
    {
        return count(array_filter($this->items, static fn (Finding $f): bool => $f->severity === $severity));
    }

    /** A release may proceed when nothing failed; `strict` also refuses warnings. */
    public function passes(bool $strict = false): bool
    {
        return ! $this->hasFailures() && (! $strict || $this->count(Severity::Warning) === 0);
    }

    /** @return list<array{check: string, severity: string, message: string}> */
    public function toArray(): array
    {
        return array_map(static fn (Finding $f): array => [
            'check' => $f->check,
            'severity' => $f->severity->value,
            'message' => $f->message,
        ], $this->items);
    }
}
