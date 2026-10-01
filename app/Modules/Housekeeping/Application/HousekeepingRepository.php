<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Housekeeping\Domain\CleaningStatus;
use App\Modules\Housekeeping\Domain\HousekeepingTask;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface HousekeepingRepository
{
    public const CREATED = 'created';

    /** This source reference already created a task. */
    public const DUPLICATE = 'duplicate';

    /** The room already has an unfinished task. */
    public const ROOM_BUSY = 'room_busy';

    /** @return array{inspection_required: bool, lock_version: int} */
    public function settings(PropertyId $property): array;

    /** @return bool false when the settings changed first */
    public function saveSettings(PropertyId $property, bool $inspectionRequired, int $expectedLockVersion, string $actorId): bool;

    /**
     * The room's state under a row lock, creating it as ready when it has none. Call inside a transaction.
     *
     * @return array{status: CleaningStatus, lock_version: int}
     */
    public function lockRoom(PropertyId $property, string $roomId, DateTimeImmutable $at): array;

    /** Moves the room and appends to the status log. */
    public function setStatus(PropertyId $property, string $roomId, ?CleaningStatus $from, CleaningStatus $to, ?string $actorId, ?string $reason, ?string $taskId, DateTimeImmutable $at): void;

    /** @return array<string, string> */
    public function statuses(PropertyId $property): array;

    /** @return 'created'|'duplicate'|'room_busy' */
    public function addTask(PropertyId $property, HousekeepingTask $task, int $priority, string $source, ?string $sourceRef, ?string $createdBy, ?string $assignedBy, DateTimeImmutable $at): string;

    public function findTask(PropertyId $property, string $id): ?HousekeepingTask;

    /** @return bool false when someone changed the task first */
    public function saveTask(PropertyId $property, HousekeepingTask $task, int $expectedLockVersion, ?string $assignedBy, DateTimeImmutable $at): bool;

    public function activeTaskOfRoom(PropertyId $property, string $roomId): ?HousekeepingTask;

    /** Active tasks, most urgent first. @return list<array{task: HousekeepingTask, priority: int, source: string}> */
    public function activeTasks(PropertyId $property): array;

    /** Active tasks of one person, most urgent first. @return list<array{task: HousekeepingTask, priority: int, source: string}> */
    public function tasksOf(PropertyId $property, string $userId): array;

    /** The person who last finished a task in this room, for sending rework back to them. */
    public function lastAttendant(PropertyId $property, string $roomId): ?string;

    /** @param list<array{id: string, description: string, mandatory: bool}> $findings */
    public function addInspection(PropertyId $property, string $id, string $roomId, string $inspectorId, string $result, ?string $notes, array $findings, DateTimeImmutable $at): void;

    /** Findings still open for a room. @return list<array{id: string, description: string, mandatory: bool, lock_version: int}> */
    public function openFindings(PropertyId $property, string $roomId): array;

    /** @return array{id: string, room_id: string, status: string, lock_version: int}|null */
    public function findFinding(PropertyId $property, string $id): ?array;

    /** @return bool false when the finding is no longer open or changed first */
    public function closeFinding(PropertyId $property, string $id, string $status, string $actorId, ?string $waiveReason, int $expectedLockVersion, DateTimeImmutable $at): bool;

    /** @return list<array{id: string, inspector_id: string, result: string, notes: ?string, inspected_at: string}> newest first */
    public function inspectionsOf(PropertyId $property, string $roomId, int $limit): array;

    // ---- service flags (FR-HK-017) ----

    /** @return bool false when the room already has an open flag of this kind */
    public function addFlag(PropertyId $property, string $id, string $roomId, string $kind, ?string $note, string $actorId, DateTimeImmutable $at, bool $instant): bool;

    /** @return array<string, mixed>|null */
    public function findFlag(PropertyId $property, string $id): ?array;

    /** @return bool false when the flag changed or was already ended */
    public function endFlag(PropertyId $property, string $id, int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool;

    /** Flags not yet ended, by room id. @return array<string, list<array<string, mixed>>> */
    public function openFlags(PropertyId $property): array;

    /** @return list<array<string, mixed>> newest first */
    public function flagHistory(PropertyId $property, string $roomId, int $limit): array;
}
