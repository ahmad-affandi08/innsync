<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Identifiers;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use Illuminate\Support\Str;

final class UlidIdentifierGenerator implements IdentifierGenerator
{
    public function next(): string
    {
        return strtolower((string) Str::ulid());
    }
}
