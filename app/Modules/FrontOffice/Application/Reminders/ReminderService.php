<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reminders;

use App\Modules\FrontOffice\Application\FrontDeskAccess;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * What the desk must remember: a wake-up call, extra towels, a guest to call back. Each has a day and an optional time (the property's local time), may point to a
 * reservation or a room, and is done by whoever is on duty. "Due" is counted by the business date: a reminder for today or an earlier day that is still open.
 */
final readonly class ReminderService
{
    public function __construct(private ReminderStore $store, private FrontDeskAccess $access, private BusinessDateProvider $businessDate, private IdentifierGenerator $ids, private TransactionRunner $transactions, private AuditTrail $audit) {}

    /** @return array{today: string, open: list<array<string, mixed>>, done: list<array<string, mixed>>, may_write: bool} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->read($property, $actorId);

        return ['today' => $this->businessDate->current($property)->toString(), 'open' => $this->store->list($property, 'open', 200), 'done' => $this->store->list($property, 'done', 30), 'may_write' => $this->access->mayWrite($property, $actorId)];
    }

    public function dueCount(PropertyId $property, string $actorId): int
    {
        $this->access->read($property, $actorId);

        return $this->store->dueCount($property, $this->businessDate->current($property)->toString());
    }

    public function add(PropertyId $property, string $actorId, string $dueOn, ?string $dueTime, string $text, ?string $reservationId, ?string $roomId): string
    {
        $this->access->write($property, $actorId);
        $text = trim($text);
        $dueTime = $dueTime === null || $dueTime === '' ? null : $dueTime;

        if ($text === '' || mb_strlen($text) > 300) {
            throw Refusal::invalid('Write what to remember, in at most 300 characters.', ['text']);
        }

        if (DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn) === false || DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn)->format('Y-m-d') !== $dueOn) {
            throw Refusal::invalid('Choose the day.', ['due_on']);
        }

        if ($dueOn < $this->businessDate->current($property)->toString()) {
            throw Refusal::invalid('The day is not before today.', ['due_on']);
        }

        if ($dueTime !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dueTime) !== 1) {
            throw Refusal::invalid('Give the time as hours and minutes, for example 05:00.', ['due_time']);
        }

        $reservationId = $reservationId === null || $reservationId === '' ? null : strtolower($reservationId);
        $roomId = $roomId === null || $roomId === '' ? null : strtolower($roomId);

        if ($reservationId !== null && ! $this->store->reservationExists($property, $reservationId)) {
            throw Refusal::invalid('This reservation does not exist.', ['reservation_id']);
        }

        if ($roomId !== null && ! $this->store->roomExists($property, $roomId)) {
            throw Refusal::invalid('This room does not exist.', ['room_id']);
        }

        $id = $this->ids->next();
        $this->transactions->run(function () use ($property, $actorId, $id, $dueOn, $dueTime, $text, $reservationId, $roomId): void {
            $this->store->add($property, $id, $dueOn, $dueTime, $text, $reservationId, $roomId, strtolower($actorId));
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'front_desk.reminder.added', 'fo_reminder', $id, null, ['due_on' => $dueOn, 'due_time' => $dueTime, 'reservation_id' => $reservationId, 'room_id' => $roomId]));
        });

        return $id;
    }

    public function done(PropertyId $property, string $actorId, string $id): void
    {
        $this->access->write($property, $actorId);

        $this->transactions->run(function () use ($property, $actorId, $id): void {
            if (! $this->store->markDone($property, strtolower($id), strtolower($actorId))) {
                throw Refusal::stateConflict('This reminder is already done, or does not exist.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'front_desk.reminder.done', 'fo_reminder', strtolower($id), ['status' => 'open'], ['status' => 'done']));
        });
    }

    public function reopen(PropertyId $property, string $actorId, string $id): void
    {
        $this->access->write($property, $actorId);

        $this->transactions->run(function () use ($property, $actorId, $id): void {
            if (! $this->store->reopen($property, strtolower($id))) {
                throw Refusal::stateConflict('This reminder is not done, or does not exist.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'front_desk.reminder.reopened', 'fo_reminder', strtolower($id), ['status' => 'done'], ['status' => 'open']));
        });
    }
}
