<?php

declare(strict_types=1);

namespace App\Shared\Application\Errors;

use RuntimeException;

/**
 * A business rule refused an action in an expected way (not a defect): forbidden, invalid input, a state conflict or
 * a missing record. Rendered in the standard error envelope. `$fields` names the inputs to highlight for 422.
 */
final class Refusal extends RuntimeException implements ExpectedFailure
{
    /** @param list<string> $fields */
    private function __construct(private readonly int $httpStatus, private readonly array $fields, string $message)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, [], $message);
    }

    /** @param list<string> $fields */
    public static function invalid(string $message, array $fields = []): self
    {
        return new self(422, $fields, $message);
    }

    public static function stateConflict(string $message): self
    {
        return new self(409, [], $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, [], $message);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return match ($this->httpStatus) {
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            default => 'validation_failed',
        };
    }

    public function messageKey(): string
    {
        return match ($this->httpStatus) {
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict_state',
            default => 'validation_failed',
        };
    }

    public function conflict(): ?array
    {
        return $this->httpStatus === 409 ? ['reason' => 'state', 'action' => 'refresh'] : null;
    }

    public function invalidFields(): array
    {
        return $this->fields;
    }
}
