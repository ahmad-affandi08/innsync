<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

enum IdempotencySecurityEvent: string
{
    case Conflict = 'idempotency.conflict';
    case StoredResultInvalid = 'idempotency.stored-result.invalid';
}
