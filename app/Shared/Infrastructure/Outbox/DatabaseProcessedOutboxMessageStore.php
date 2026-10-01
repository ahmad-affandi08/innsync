<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\ProcessedOutboxMessageStore;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseProcessedOutboxMessageStore implements ProcessedOutboxMessageStore
{
    private const UNIQUE_INDEX = 'outbox_consumer_event_unique';

    public function __construct(private PropertyContext $propertyContext) {}

    public function claim(PropertyId $propertyId, string $eventId, string $consumer): bool
    {
        $this->assertPropertyScope($propertyId);

        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $consumer) !== 1) {
            throw new \InvalidArgumentException('The outbox consumer name is invalid.');
        }

        try {
            DB::table('processed_outbox_messages')->insert([
                'id' => strtolower((string) Str::ulid()),
                'property_id' => $propertyId->toString(),
                'event_id' => strtolower($eventId),
                'consumer' => $consumer,
                'processed_at' => now('UTC'),
            ]);

            return true;
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062
                && str_contains($exception->getMessage(), self::UNIQUE_INDEX)) {
                return false;
            }

            throw $exception;
        }
    }

    private function assertPropertyScope(PropertyId $propertyId): void
    {
        $activePropertyId = $this->propertyContext->current()->toString();

        if ($activePropertyId !== $propertyId->toString()) {
            throw PropertyScopeViolation::mismatched($activePropertyId, $propertyId->toString());
        }
    }
}
