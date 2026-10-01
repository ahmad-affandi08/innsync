<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

/**
 * The server's answer for one queued item. `retry_later` (transient) and
 * `deferred` (not attempted, to keep a device's order) are not final: the
 * client keeps the item. The other three are final for that operation ID.
 */
final readonly class OfflineItemResult
{
    public const ACCEPTED = 'accepted';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    public const RETRY_LATER = 'retry_later';

    public const DEFERRED = 'deferred';

    /** @param array<string, mixed> $result */
    private function __construct(
        public string $operationId,
        public string $status,
        public bool $replayed = false,
        public ?int $serverVersion = null,
        public array $result = [],
        public ?string $code = null,
        public ?string $action = null,
    ) {}

    public static function fromOutcome(string $operationId, OfflineOutcome $outcome, bool $replayed): self
    {
        return new self(
            $operationId,
            $outcome->status,
            $replayed,
            $outcome->serverVersion,
            $outcome->result,
            $outcome->code,
            $outcome->action?->value,
        );
    }

    public static function rejected(string $operationId, string $code): self
    {
        return new self($operationId, self::REJECTED, code: $code);
    }

    public static function retryLater(string $operationId, string $code): self
    {
        return new self($operationId, self::RETRY_LATER, code: $code);
    }

    public static function deferred(string $operationId): self
    {
        return new self($operationId, self::DEFERRED, code: 'earlier_item_pending');
    }

    public function isFinalProblem(): bool
    {
        return $this->status === self::CONFLICT || $this->status === self::REJECTED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'status' => $this->status,
            'replayed' => $this->replayed,
            'server_version' => $this->serverVersion,
            'result' => $this->result === [] ? new \stdClass : $this->result,
            'code' => $this->code,
            'action' => $this->action,
        ];
    }
}
