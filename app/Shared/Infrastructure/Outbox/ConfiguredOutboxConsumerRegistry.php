<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxConsumerRegistry;
use InvalidArgumentException;

final class ConfiguredOutboxConsumerRegistry implements OutboxConsumerRegistry
{
    /** @var list<OutboxConsumer> */
    private array $consumers;

    /** @param iterable<OutboxConsumer> $consumers */
    public function __construct(iterable $consumers)
    {
        $this->consumers = [];
        $names = [];

        foreach ($consumers as $consumer) {
            if (! $consumer instanceof OutboxConsumer) {
                throw new InvalidArgumentException('Every configured outbox consumer must implement OutboxConsumer.');
            }

            $name = $consumer->name();

            if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $name) !== 1) {
                throw new InvalidArgumentException('The outbox consumer name is invalid.');
            }

            if (isset($names[$name])) {
                throw new InvalidArgumentException(sprintf('Duplicate outbox consumer name: %s.', $name));
            }

            $names[$name] = true;
            $this->consumers[] = $consumer;
        }
    }

    public function forEvent(string $eventType): array
    {
        return array_values(array_filter(
            $this->consumers,
            static fn (OutboxConsumer $consumer): bool => $consumer->supports($eventType),
        ));
    }
}
