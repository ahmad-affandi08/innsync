<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\RequeueDeadLetter;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RetryDeadLetterCommand extends Command
{
    protected $signature = 'outbox:retry {property_id : Property ULID} {event_id : Outbox event ULID}';

    protected $description = 'Requeue one reviewed dead-letter outbox message';

    public function handle(
        RequeueDeadLetter $requeue,
        PropertyContext $propertyContext,
    ): int {
        try {
            $propertyId = PropertyId::fromString((string) $this->argument('property_id'));
            $eventId = strtolower((string) $this->argument('event_id'));

            if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di', $eventId) !== 1) {
                throw new InvalidArgumentException('Event ID must be a ULID.');
            }

            $propertyContext->run(
                $propertyId,
                fn () => $requeue->execute($propertyId, $eventId),
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $this->components->info(sprintf('Outbox message %s was requeued.', $eventId));

        return self::SUCCESS;
    }
}
