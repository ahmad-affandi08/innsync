<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use InvalidArgumentException;

/**
 * What a handler decided. There is deliberately no "overwrite" outcome: a change
 * that could alter money, stock, room availability or approval state is either
 * accepted on deterministic rules, or returned as a conflict or rejection for a
 * person to resolve. No last-write-wins.
 */
final readonly class OfflineOutcome
{
    public const ACCEPTED = 'accepted';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    /** @param array<string, mixed> $result */
    private function __construct(
        public string $status,
        public ?int $serverVersion,
        public array $result,
        public ?string $code,
        public ?ConflictAction $action,
    ) {}

    /**
     * @param  array<string, mixed>  $result  Small, non-sensitive result the client may show.
     * @param  ?int  $serverVersion  The canonical version after applying the change.
     */
    public static function accepted(array $result = [], ?int $serverVersion = null): self
    {
        return new self(self::ACCEPTED, $serverVersion, $result, null, null);
    }

    /** The server's state differs from what the client based its change on. */
    public static function conflict(string $reasonCode, ConflictAction $action, ?int $serverVersion = null): self
    {
        return new self(self::CONFLICT, $serverVersion, [], self::assertCode($reasonCode), $action);
    }

    /** The change is not valid under current rules; retrying the same payload will not help. */
    public static function rejected(string $reasonCode): self
    {
        return new self(self::REJECTED, null, [], self::assertCode($reasonCode), null);
    }

    /** @return array<string, mixed> storable as the idempotent logical result */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'server_version' => $this->serverVersion,
            'result' => $this->result,
            'code' => $this->code,
            'action' => $this->action?->value,
        ];
    }

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $status = $stored['status'] ?? null;

        if (! in_array($status, [self::ACCEPTED, self::CONFLICT, self::REJECTED], true)) {
            throw new InvalidArgumentException('A stored offline outcome has an unknown status.');
        }

        $version = $stored['server_version'] ?? null;
        $action = $stored['action'] ?? null;

        return new self(
            $status,
            is_int($version) ? $version : null,
            is_array($stored['result'] ?? null) ? $stored['result'] : [],
            is_string($stored['code'] ?? null) ? $stored['code'] : null,
            is_string($action) ? ConflictAction::from($action) : null,
        );
    }

    private static function assertCode(string $code): string
    {
        if (preg_match('/^[a-z][a-z0-9_.]{1,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A reason code is a short lowercase identifier.');
        }

        return $code;
    }
}
