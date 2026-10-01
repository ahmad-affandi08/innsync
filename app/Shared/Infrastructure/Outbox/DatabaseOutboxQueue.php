<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class DatabaseOutboxQueue implements OutboxQueue
{
    public function enqueueDue(int $limit): int
    {
        $this->assertAtomicDatabaseQueue();

        return DB::transaction(function () use ($limit): int {
            $messages = DB::table('outbox_messages')
                ->select(['id', 'property_id', 'correlation_id'])
                ->where('status', 'pending')
                ->where('available_at', '<=', now('UTC'))
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            /** @var DatabaseQueue $queue */
            $queue = Queue::connection((string) config('outbox.queue_connection'));
            $queuedAt = now('UTC');

            foreach ($messages as $message) {
                $queue->pushOn(
                    (string) config('outbox.queue_name'),
                    new DeliverOutboxMessage(
                        (string) $message->property_id,
                        (string) $message->id,
                        (string) $message->correlation_id,
                    ),
                );

                $updated = DB::table('outbox_messages')
                    ->where('id', $message->id)
                    ->where('property_id', $message->property_id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'queued',
                        'queued_at' => $queuedAt,
                        'updated_at' => $queuedAt,
                    ]);

                if ($updated !== 1) {
                    throw new LogicException('The outbox queue handoff lost its locked message.');
                }
            }

            return $messages->count();
        }, 3);
    }

    private function assertAtomicDatabaseQueue(): void
    {
        $connection = (string) config('outbox.queue_connection');

        if ($connection !== 'database' || ! Queue::connection($connection) instanceof DatabaseQueue) {
            throw new LogicException('The outbox requires Laravel\'s database queue connection.');
        }

        $queueDatabase = config('queue.connections.database.connection');
        $applicationDatabase = config('database.default');

        if ($queueDatabase !== null && $queueDatabase !== $applicationDatabase) {
            throw new LogicException('The outbox and database queue must use the same database connection.');
        }

        if ((bool) config('queue.connections.database.after_commit')) {
            throw new LogicException('The outbox database queue handoff must participate in its current transaction.');
        }
    }
}
