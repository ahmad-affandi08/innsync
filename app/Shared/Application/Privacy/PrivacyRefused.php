<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** A retention, hold, consent, request or export rule refused the action; rendered as a standard error. */
final class PrivacyRefused extends RuntimeException implements ExpectedFailure
{
    /** @param list<string> $fields */
    private function __construct(
        private readonly int $httpStatus,
        private readonly string $key,
        private readonly array $fields,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'forbidden', [], $message);
    }

    /** @param list<string> $fields */
    public static function invalid(string $message, array $fields = []): self
    {
        return new self(422, 'validation_failed', $fields, $message);
    }

    public static function stateConflict(string $message): self
    {
        return new self(409, 'conflict_privacy_state', [], $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'not_found', [], $message);
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
        return $this->key;
    }

    public function conflict(): ?array
    {
        return $this->httpStatus === 409 ? ['reason' => 'privacy_state', 'action' => 'refresh'] : null;
    }

    public function invalidFields(): array
    {
        return $this->fields;
    }
}
