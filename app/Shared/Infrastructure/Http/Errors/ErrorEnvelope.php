<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Errors;

/**
 * Stable JSON error contract. Clients branch on `code`/`conflict`, never on `message`
 * (human text, localized later by TASK-FND-013). Messages are fixed strings: exception
 * messages, SQL, identifiers, and stack traces never reach the response.
 */
final readonly class ErrorEnvelope
{
    /**
     * @param  array<string, list<string>>|null  $fields  validation messages keyed by input name
     * @param  array{reason: string, action: 'refresh'|'retry'|'review'}|null  $conflict
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public bool $retryable = false,
        public ?array $fields = null,
        public ?array $conflict = null,
    ) {}

    /** @return array{error: array<string, mixed>} */
    public function toArray(string $correlationId): array
    {
        return ['error' => array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'status' => $this->status,
            'retryable' => $this->retryable,
            'correlation_id' => $correlationId,
            'fields' => $this->fields,
            'conflict' => $this->conflict,
        ], static fn (mixed $value): bool => $value !== null)];
    }
}
