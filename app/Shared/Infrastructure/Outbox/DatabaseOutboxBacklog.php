<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxBacklog;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseOutboxBacklog implements OutboxBacklog
{
    public function waitingSince(PropertyId $propertyId, DateTimeImmutable $before): int
    {
        return DB::table('outbox_messages')
            ->where('property_id', $propertyId->toString())
            ->whereIn('status', ['pending', 'queued', 'retrying'])
            ->where('occurred_at', '<', $before->format('Y-m-d H:i:s.u'))
            ->count();
    }
}
