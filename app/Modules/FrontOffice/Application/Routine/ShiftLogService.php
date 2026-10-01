<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Routine;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The handover log between front desk shifts (FR-FO-034): what the outgoing shift leaves for the incoming one. An entry is written at
 * the end of a shift (dated with the business date, never changed or deleted) and every person marks what they have read, so the
 * start of the next shift can show what is still unread. Important entries are announced.
 */
final readonly class ShiftLogService
{
    public const WRITE_PERMISSION = 'front-office.logbook.write';

    public const READ_PERMISSION = 'front-office.logbook.read';

    public const SHIFTS = ['morning', 'afternoon', 'night'];

    /** Entries from the last three days are kept in view; older ones stay on record. */
    private const WINDOW_DAYS = 3;

    public function __construct(
        private RoutineRepository $routine,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array<string, mixed> */
    public function write(PropertyId $property, string $actorId, string $shift, string $body, bool $important): array
    {
        $this->authorize($property, $actorId, [self::WRITE_PERMISSION]);
        $body = trim($body);

        if (! in_array($shift, self::SHIFTS, true) || $body === '' || mb_strlen($body) > 2000) {
            throw Refusal::invalid('Choose the shift and write what the next one needs to know, in at most 2000 characters.', ['shift', 'body']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $shift, $body, $important, $id): void {
            $today = $this->businessDate->current($property);
            $this->routine->addEntry($property, $id, $today->toString(), $shift, $important ? 'important' : 'normal', $body, $actor, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'shift_log.written', 'shift_log_entry', $id, null, ['shift' => $shift, 'business_date' => $today->toString(), 'priority' => $important ? 'important' : 'normal']));

            if ($important) {
                $this->outbox->publish(new OutboxEvent($property, 'frontoffice.shift_log.important', $id, 1, ['entry_id' => $id, 'shift' => $shift, 'business_date' => $today->toString(), 'actor_id' => $actor]));
            }
        });

        return $this->read($property, $actorId)['entries'][0];
    }

    /**
     * The recent entries with what this person has not read yet.
     *
     * @return array{entries: list<array<string, mixed>>, unread: int, may_write: bool, shifts: list<string>}
     */
    public function read(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::READ_PERMISSION, self::WRITE_PERMISSION]);
        $since = $this->clock->nowUtc()->modify('-'.self::WINDOW_DAYS.' days')->format('Y-m-d H:i:s.u');
        $entries = $this->routine->entries($property, strtolower($actorId), $since, 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($entries, 'author_id'))));

        return [
            'entries' => array_map(static fn (array $e): array => [...$e, 'author_name' => $names[$e['author_id']] ?? null], $entries),
            'unread' => count(array_filter($entries, static fn (array $e): bool => ! $e['read'])),
            'may_write' => $this->permissions->allowsInProperty($actorId, self::WRITE_PERMISSION, $property),
            'shifts' => self::SHIFTS,
        ];
    }

    /**
     * Marks entries as read by this person. Nothing else about an entry changes.
     *
     * @param  list<string>  $entryIds
     */
    public function markRead(PropertyId $property, string $actorId, array $entryIds): int
    {
        $this->authorize($property, $actorId, [self::READ_PERMISSION, self::WRITE_PERMISSION]);

        if (count($entryIds) > 100) {
            throw Refusal::invalid('Mark at most 100 entries at a time.', ['entries']);
        }

        return $this->transactions->run(fn (): int => $this->routine->markRead($property, strtolower($actorId), array_map('strtolower', $entryIds), $this->clock->nowUtc()));
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use the shift log.');
    }
}
