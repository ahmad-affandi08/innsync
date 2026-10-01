<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Application\Observability\CorrelationId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final class LaravelCorrelationId implements CorrelationId
{
    public function current(): string
    {
        $correlationId = Context::get('correlation_id');

        if (! is_string($correlationId) || $correlationId === '') {
            $correlationId = strtolower((string) Str::ulid());
            Context::add('correlation_id', $correlationId);
        }

        return $correlationId;
    }
}
