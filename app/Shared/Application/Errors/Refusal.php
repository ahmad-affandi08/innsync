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
    private function __construct(private readonly int $httpStatus, private readonly array $fields, string $message, private readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    /**
     * The `$reason` of each factory is a stable code with a fixed, translated sentence in the `errors` translation file of each language (`reason_<code>`): when there is one, the person is told that sentence instead of the
     * general one for the status ("Open your cashier shift first" instead of "this data changed, reload"). The English `$message` stays for logs and tests and never reaches the response.
     */
    public static function forbidden(string $message, ?string $reason = null): self
    {
        return new self(403, [], $message, $reason);
    }

    /** @param list<string> $fields */
    public static function invalid(string $message, array $fields = [], ?string $reason = null): self
    {
        return new self(422, $fields, $message, $reason);
    }

    public static function stateConflict(string $message, ?string $reason = null): self
    {
        return new self(409, [], $message, $reason);
    }

    public static function notFound(string $message, ?string $reason = null): self
    {
        return new self(404, [], $message, $reason);
    }

    /** The stable code of the sentence to show, or null for the general one of the status. */
    public function reason(): ?string
    {
        return $this->reason;
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
