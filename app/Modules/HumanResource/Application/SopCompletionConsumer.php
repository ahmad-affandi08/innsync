<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;

/**
 * How much of their daily, weekly and monthly routines the operational modules did, and who did it (FR-HR-020). The front desk, housekeeping, the kitchen, the outlets and the engineering duties
 * each tell Human Resource when an item is ticked or a round is done; this keeps the run with how far it got and credits the person who did each item, once however often the event is delivered.
 * It is the objective part of the appraisal of a person: it counts, it does not judge.
 */
final readonly class SopCompletionConsumer implements OutboxConsumer
{
    /** The event of each source: an item ticked (or, for the duties, a round done). */
    private const SOURCES = [
        'frontoffice.sop.item_completed' => 'front_office',
        'housekeeping.checklist.item_completed' => 'housekeeping',
        'kitchen.sop.item_completed' => 'kitchen',
        'fnb.sop.item_completed' => 'fnb',
        'maintenance.duty.completed' => 'maintenance',
    ];

    public function __construct(private PerformanceStore $store, private IdentifierGenerator $ids) {}

    public function name(): string
    {
        return 'hr.sop-completion';
    }

    public function supports(string $eventType): bool
    {
        return isset(self::SOURCES[$eventType]);
    }

    public function consume(OutboxMessage $message): void
    {
        $event = $message->event;
        $d = $event->data;
        $source = self::SOURCES[$event->eventType];
        $at = $message->occurredAt;
        $run = strtolower((string) $d['run_id']);
        $duty = $event->eventType === 'maintenance.duty.completed';
        $total = (int) $d['total'];
        $completed = $duty ? $total : (int) $d['completed'];

        $this->store->noteRun($event->propertyId, $this->ids->next(), $source, $run, (string) $d['checklist'], (string) $d['frequency'], (string) $d['period'], self::start((string) $d['period']), $total, $completed, $at);
        $this->store->credit($event->propertyId, $this->ids->next(), $source, $run, $duty ? 'run' : (string) $d['item_id'].(isset($d['target']) ? ':'.mb_substr((string) $d['target'], 0, 40) : ''), strtolower((string) $d['completed_by']), $duty ? max(1, $total) : 1, $at->format('Y-m-d'), $at);
    }

    /** The first day of a period key: a day, an ISO week (`2026-W41`) or a month (`2026-10`). */
    private static function start(string $key): string
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) === 1) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setISODate((int) $m[1], (int) $m[2], 1)->format('Y-m-d');
        }

        return strlen($key) === 7 ? $key.'-01' : $key;
    }
}
