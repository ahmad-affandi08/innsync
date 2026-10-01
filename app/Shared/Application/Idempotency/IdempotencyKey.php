<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use InvalidArgumentException;

final readonly class IdempotencyKey
{
    private const PATTERN = '/^[A-Za-z0-9._:-]{16,128}$/D';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'An idempotency key must contain 16-128 safe ASCII characters.',
            );
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
