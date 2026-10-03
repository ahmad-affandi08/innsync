<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Modules\FnbSales\Application\FnbTime;
use App\Modules\FnbSales\Application\MenuAvailability;
use App\Modules\InventoryPurchasing\Application\IngredientCatalog;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * The screens of the kitchen and the bar (FR-KIT-001, FR-KIT-002, FR-KIT-005, FR-KIT-015). A ticket goes new, preparing, ready and served; whoever moves it is kept, and
 * every move is told to the point of sale, so the waiter sees what is ready. A ticket that waits longer than the property allows is marked late. The screen can be reloaded
 * at any time and says when it last got the tickets, so a cook on a failing network knows what they see is old. A dish that runs out is marked sold out here and cannot be
 * ordered until it is put back.
 */
final readonly class BoardService
{
    public const PROGRESS_EVENT = 'kitchen.ticket.progressed';

    /** How long a ticket may wait before it is late, when the property has not set it. */
    public const BASELINE_LATE_MINUTES = 15;

    /** How far back the served tickets stay on the screen, and how many. */
    private const SERVED_HOURS = 3;

    private const SERVED_LIMIT = 30;

    private const TRANSITIONS = ['start' => ['from' => ['new'], 'to' => 'preparing'], 'ready' => ['from' => ['new', 'preparing'], 'to' => 'ready'], 'serve' => ['from' => ['ready'], 'to' => 'served']];

    public function __construct(
        private TicketStore $store,
        private KitchenAccess $access,
        private MenuAvailability $menu,
        private IngredientCatalog $ingredients,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function board(PropertyId $property, string $actorId, string $station): array
    {
        $this->access->require($property, $actorId, KitchenAccess::BOARD_OPERATE, 'This person may not work the kitchen screen.');
        $station = $this->station($station);
        $now = $this->clock->nowUtc();
        $late = $this->lateAfter($property);
        $tickets = array_map(fn (array $t): array => $this->view($t, $now, $late), $this->store->open($property, $station));
        $served = array_map(fn (array $t): array => $this->view($t, $now, $late), $this->store->servedSince($property, $station, $now->modify('-'.self::SERVED_HOURS.' hours'), self::SERVED_LIMIT));
        $counts = $this->store->openCounts($property);

        return [
            'station' => $station,
            'stations' => array_map(static fn (string $s): array => ['key' => $s, 'open' => (int) ($counts[$s] ?? 0)], TicketIntakeConsumer::STATIONS),
            'tickets' => $tickets,
            'served' => $served,
            'late_after_minutes' => $late,
            'loaded_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'may' => ['settings' => $this->access->may($property, $actorId, KitchenAccess::SETTINGS_MANAGE)],
        ];
    }

    /** @return array<string, mixed> the ticket as it is now */
    public function advance(PropertyId $property, string $actorId, string $ticketId, string $action, int $lock): array
    {
        $this->access->require($property, $actorId, KitchenAccess::BOARD_OPERATE, 'This person may not work the kitchen screen.');
        $move = self::TRANSITIONS[$action] ?? throw Refusal::invalid('Choose what to do with the ticket.', ['action']);
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $ticketId, $move, $lock, $now): void {
            $id = strtolower($ticketId);
            $this->store->lockTicket($property, $id);
            $ticket = $this->store->ticket($property, $id) ?? throw Refusal::notFound('Ticket not found.');

            if ((int) $ticket['lock_version'] !== $lock) {
                throw Refusal::stateConflict('This ticket changed after you saw it. Reload the screen.');
            }

            if (! in_array($ticket['status'], $move['from'], true)) {
                throw Refusal::stateConflict($ticket['status'] === 'cancelled' ? 'This ticket was cancelled.' : 'This ticket is not at the step you chose. Reload the screen.');
            }

            $fields = ['status' => $move['to']];

            if ($move['to'] === 'preparing') {
                $fields += ['started_at' => $now, 'started_by' => $actor];
            } elseif ($move['to'] === 'ready') {
                $fields += ['ready_at' => $now, 'ready_by' => $actor] + ($ticket['started_at'] === null ? ['started_at' => $now, 'started_by' => $actor] : []);
            } else {
                $fields += ['served_at' => $now, 'served_by' => $actor];
            }

            if (! $this->store->advance($property, $id, $lock, $fields, $now)) {
                throw Refusal::stateConflict('This ticket changed after you saw it. Reload the screen.');
            }

            $lineIds = array_values(array_map(static fn (array $l): string => (string) $l['line_id'], array_filter($ticket['lines'], static fn (array $l): bool => ! (bool) $l['cancelled'])));
            $this->outbox->publish(new OutboxEvent($property, self::PROGRESS_EVENT, $id, 1, [
                'ticket_id' => $id, 'status' => $move['to'], 'station' => $ticket['station'], 'bill_id' => $ticket['bill_id'], 'bill_number' => $ticket['bill_number'], 'line_ids' => $lineIds, 'actor_id' => $actor,
            ]));
        });

        return $this->view($this->store->ticket($property, strtolower($ticketId)) ?? throw Refusal::notFound('Ticket not found.'), $now, $this->lateAfter($property));
    }

    /** @return array<string, mixed> */
    public function soldOut(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, KitchenAccess::BOARD_OPERATE, 'This person may not work the kitchen screen.');

        return ['items' => $this->menu->items($property)];
    }

    /** @return array<string, mixed> */
    public function setAvailability(PropertyId $property, string $actorId, string $itemId, bool $available): array
    {
        $this->access->require($property, $actorId, KitchenAccess::BOARD_OPERATE, 'This person may not work the kitchen screen.');
        $this->menu->set($property, $actorId, $itemId, $available);

        return $this->soldOut($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function settings(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, KitchenAccess::BOARD_OPERATE, 'This person may not work the kitchen screen.');
        $row = $this->store->settings($property);

        $manage = $this->access->may($property, $actorId, KitchenAccess::SETTINGS_MANAGE);

        return [
            'late_after_minutes' => $row['late_after_minutes'] ?? self::BASELINE_LATE_MINUTES, 'stock_location_id' => $row['stock_location_id'] ?? null, 'lock_version' => $row['lock_version'] ?? null, 'is_baseline' => $row === null,
            'locations' => $manage ? $this->ingredients->locations($property) : [], 'may' => ['manage' => $manage],
        ];
    }

    /** @return array<string, mixed> */
    public function saveSettings(PropertyId $property, string $actorId, int $lateAfterMinutes, ?string $stockLocationId, ?int $lock, string $reason): array
    {
        $this->access->require($property, $actorId, KitchenAccess::SETTINGS_MANAGE, 'This person may not set the kitchen screen.');
        $reason = trim($reason);

        if ($lateAfterMinutes < 1 || $lateAfterMinutes > 240) {
            throw Refusal::invalid('A ticket may wait between 1 and 240 minutes before it is late.', ['late_after_minutes']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason, in at most 200 characters.', ['reason']);
        }

        $stockLocationId = $stockLocationId === null || $stockLocationId === '' ? null : strtolower($stockLocationId);

        if ($stockLocationId !== null && ! in_array($stockLocationId, array_column($this->ingredients->locations($property), 'id'), true)) {
            throw Refusal::invalid('Choose a location of the inventory that is in use.', ['stock_location_id']);
        }

        $actor = strtolower($actorId);
        $before = $this->store->settings($property);

        $this->transactions->run(function () use ($property, $actor, $lateAfterMinutes, $stockLocationId, $lock, $reason, $before): void {
            if (($before['lock_version'] ?? null) !== $lock) {
                throw Refusal::stateConflict('This setting changed after you opened it. Reload it.');
            }

            if (! $this->store->saveSettings($property, $lateAfterMinutes, $stockLocationId, $before === null ? null : (int) $before['lock_version'], $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This setting changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'kitchen_settings.changed', 'kitchen_settings', $property->toString(), ['late_after_minutes' => $before['late_after_minutes'] ?? self::BASELINE_LATE_MINUTES, 'stock_location_id' => $before['stock_location_id'] ?? null], ['late_after_minutes' => $lateAfterMinutes, 'stock_location_id' => $stockLocationId], $reason));
        });

        return $this->settings($property, $actorId);
    }

    private function station(string $station): string
    {
        return in_array($station, TicketIntakeConsumer::STATIONS, true) ? $station : throw Refusal::invalid('Choose the kitchen or the bar.', ['station']);
    }

    private function lateAfter(PropertyId $property): int
    {
        return $this->store->settings($property)['late_after_minutes'] ?? self::BASELINE_LATE_MINUTES;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function view(array $t, DateTimeImmutable $now, int $lateAfter): array
    {
        $received = new DateTimeImmutable((string) $t['received_at'], new \DateTimeZone('UTC'));
        $end = $t['status'] === 'served' && $t['served_at'] !== null ? new DateTimeImmutable((string) $t['served_at'], new \DateTimeZone('UTC')) : $now;
        $waiting = max(0, $end->getTimestamp() - $received->getTimestamp());

        return [
            'id' => $t['id'], 'station' => $t['station'], 'status' => $t['status'], 'bill_number' => $t['bill_number'], 'batch_number' => (int) $t['batch_number'], 'outlet_code' => $t['outlet_code'], 'place_kind' => $t['place_kind'], 'place' => $t['place'],
            'received_at' => FnbTime::utc($t['received_at']), 'started_at' => FnbTime::utc($t['started_at']), 'ready_at' => FnbTime::utc($t['ready_at']), 'served_at' => FnbTime::utc($t['served_at']),
            'waiting_seconds' => $waiting, 'is_late' => in_array($t['status'], ['new', 'preparing'], true) && $waiting >= $lateAfter * 60, 'lock_version' => (int) $t['lock_version'],
            'lines' => array_map(static fn (array $l): array => ['id' => $l['line_id'], 'name' => $l['name'], 'variant' => $l['variant'], 'modifiers' => $l['modifiers'], 'quantity' => (int) $l['quantity'], 'note' => $l['note'], 'cancelled' => (bool) $l['cancelled']], $t['lines']),
        ];
    }
}
