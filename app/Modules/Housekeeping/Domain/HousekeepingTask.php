<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

use DateTimeImmutable;

/** One servicing of a room by one attendant (FR-HK-002, FR-HK-004). Start and finish are recorded to measure the duration. */
final readonly class HousekeepingTask
{
    public function __construct(
        public string $id,
        public string $roomId,
        public TaskKind $kind,
        public TaskStatus $status,
        public ?string $assignedTo,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public int $lockVersion,
    ) {}

    public function assign(string $userId): self
    {
        if (! in_array($this->status, [TaskStatus::Open, TaskStatus::Assigned], true)) {
            throw HousekeepingRuleViolation::notAllowed('Only a task that has not started can be given to someone else.');
        }

        return $this->with(TaskStatus::Assigned, $userId, null, null);
    }

    /** Only the person it is assigned to starts it; an open task is taken by whoever starts it. */
    public function start(string $userId, DateTimeImmutable $at): self
    {
        if (! in_array($this->status, [TaskStatus::Open, TaskStatus::Assigned], true)) {
            throw HousekeepingRuleViolation::notAllowed('This task has already been started or finished.');
        }

        if ($this->assignedTo !== null && $this->assignedTo !== $userId) {
            throw HousekeepingRuleViolation::notAllowed('This task is assigned to someone else.');
        }

        return $this->with(TaskStatus::InProgress, $userId, $at, null);
    }

    public function finish(string $userId, DateTimeImmutable $at): self
    {
        if ($this->status !== TaskStatus::InProgress || $this->startedAt === null) {
            throw HousekeepingRuleViolation::notAllowed('Only a task in progress can be finished.');
        }

        if ($this->assignedTo !== $userId) {
            throw HousekeepingRuleViolation::notAllowed('This task is being done by someone else.');
        }

        return $this->with(TaskStatus::Done, $this->assignedTo, $this->startedAt, $at);
    }

    public function cancel(): self
    {
        if (! $this->status->isActive()) {
            throw HousekeepingRuleViolation::notAllowed('A finished task cannot be cancelled.');
        }

        return $this->with(TaskStatus::Cancelled, $this->assignedTo, $this->startedAt, null);
    }

    public function durationSeconds(): ?int
    {
        return $this->startedAt !== null && $this->finishedAt !== null ? $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp() : null;
    }

    private function with(TaskStatus $status, ?string $assignedTo, ?DateTimeImmutable $startedAt, ?DateTimeImmutable $finishedAt): self
    {
        return new self($this->id, $this->roomId, $this->kind, $status, $assignedTo, $startedAt, $finishedAt, $this->lockVersion);
    }
}
