<?php

declare(strict_types=1);

namespace App\Shared\Application\Identifiers;

/** Port for new aggregate identifiers, so Application code never touches a framework helper. */
interface IdentifierGenerator
{
    /** A new lowercase ULID. */
    public function next(): string;
}
