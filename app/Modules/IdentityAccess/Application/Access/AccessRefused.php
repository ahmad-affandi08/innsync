<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Access;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** User or role administration refused an action. The reason code picks the HTTP status and the human text under `errors.access_*`. */
final class AccessRefused extends RuntimeException implements ExpectedFailure
{
    public const FORBIDDEN = 'forbidden';

    public const SELF_CHANGE = 'self_change';

    public const ESCALATION = 'escalation';

    public const LAST_MANAGER = 'last_manager';

    public const PROTECTED_ROLE = 'protected_role';

    public const NOT_FOUND = 'not_found';

    public const INVALID = 'invalid';

    public const EMAIL_TAKEN = 'email_taken';

    public const DUPLICATE = 'duplicate';

    private function __construct(public readonly string $reasonCode, string $message, private readonly string $field = '')
    {
        parent::__construct($message);
    }

    public static function because(string $reasonCode, string $message, string $field = ''): self
    {
        return new self($reasonCode, $message, $field);
    }

    public function status(): int
    {
        return match ($this->reasonCode) {
            self::FORBIDDEN, self::SELF_CHANGE, self::ESCALATION => 403,
            self::NOT_FOUND => 404,
            self::INVALID, self::EMAIL_TAKEN => 422,
            default => 409,
        };
    }

    public function errorCode(): string
    {
        return match ($this->status()) {
            403 => 'forbidden',
            404 => 'not_found',
            422 => 'validation_failed',
            default => 'conflict',
        };
    }

    public function messageKey(): string
    {
        return match ($this->reasonCode) {
            self::SELF_CHANGE => 'access_self_change',
            self::ESCALATION => 'access_escalation',
            self::LAST_MANAGER => 'access_last_manager',
            self::PROTECTED_ROLE => 'access_protected_role',
            self::DUPLICATE => 'access_duplicate',
            self::EMAIL_TAKEN => 'access_email_taken',
            default => match ($this->status()) {
                403 => 'forbidden',
                404 => 'not_found',
                422 => 'validation_failed',
                default => 'conflict_state',
            },
        };
    }

    public function conflict(): ?array
    {
        return $this->status() === 409 ? ['reason' => 'access_'.$this->reasonCode, 'action' => 'review'] : null;
    }

    public function invalidFields(): array
    {
        return $this->field === '' ? [] : [$this->field];
    }
}
