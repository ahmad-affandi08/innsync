<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoredFileRepository;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Lost and found (FR-HK-012). Whoever finds something records it with a description, where it was found (a room or a place), a photo
 * and where it is kept; it stays "stored" until a person with the right returns it (to whom, with a note) or disposes of it (why).
 * The facts of the find never change and nothing is deleted. The photo is kept privately and erased by the retention rule some days
 * after the item is closed (a baseline of 90 days that the owner confirms); it is shown only to people who handle lost property.
 */
final readonly class LostFoundService
{
    public const RECORD_PERMISSION = 'housekeeping.lostfound.record';

    public const MANAGE_PERMISSION = 'housekeeping.lostfound.manage';

    public const PHOTO_PURPOSE = 'housekeeping.lostfound';

    public const PHOTO_MAX_BYTES = 5_242_880;

    private const RETENTION_CATEGORY = 'lost_found_photo';

    /** After this many days stored, an item is flagged as due for a decision (a baseline that the owner confirms). */
    public const STORED_DAYS_FLAG = 90;

    public function __construct(
        private LostFoundRepository $items,
        private RoomCatalogReader $rooms,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private StoredFileRepository $files,
        private RetentionPolicies $retention,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, rooms: list<array{id: string, number: string}>, may: array{record: bool, manage: bool}, business_date: string, flag_days: int}
     */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->authorizeAny($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['stored', 'returned', 'disposed'], true)) {
            throw Refusal::invalid('Choose stored, returned or disposed.', ['status']);
        }

        $today = $this->businessDate->current($property);
        $items = $this->items->list($property, $status === '' ? null : $status, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_merge(array_column($items, 'found_by'), array_filter(array_column($items, 'closed_by'))))));
        $rooms = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($room->isActive) {
                $rooms[] = ['id' => $room->id, 'number' => $room->number];
            }
        }

        return [
            'items' => array_map(fn (array $i): array => [...$i, 'found_by_name' => $names[$i['found_by']] ?? null, 'closed_by_name' => $i['closed_by'] === null ? null : ($names[$i['closed_by']] ?? null),
                'days_stored' => $i['status'] === 'stored' ? max(0, BusinessDate::fromString($i['found_date'])->daysUntil($today)) : null, 'has_photo' => $i['photo_file_id'] !== null], $items),
            'rooms' => $rooms,
            'may' => ['record' => $this->may($property, $actorId, self::RECORD_PERMISSION), 'manage' => $this->may($property, $actorId, self::MANAGE_PERMISSION)],
            'business_date' => $today->toString(), 'flag_days' => self::STORED_DAYS_FLAG,
        ];
    }

    /**
     * Records a found item. `$photo` is the content of an image (JPEG or PNG), optional.
     *
     * @return array<string, mixed>
     */
    public function record(PropertyId $property, string $actorId, string $description, ?string $roomId, ?string $place, string $storedAt, ?string $photo, ?string $photoName): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::RECORD_PERMISSION) && ! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not record found items.');
        }

        $description = trim($description);
        $place = $place === null || trim($place) === '' ? null : trim($place);
        $roomId = $roomId === null || $roomId === '' ? null : strtolower($roomId);
        $storedAt = trim($storedAt);

        if ($description === '' || mb_strlen($description) > 200 || $storedAt === '' || mb_strlen($storedAt) > 80 || ($place !== null && mb_strlen($place) > 80)) {
            throw Refusal::invalid('Describe what was found (at most 200 characters) and say where it is kept (at most 80).', ['description', 'stored_at', 'place']);
        }

        if ($roomId === null && $place === null) {
            throw Refusal::invalid('Say where it was found: choose a room or name the place.', ['room_id', 'place']);
        }

        if ($roomId !== null && ($this->rooms->room($property, $roomId) === null)) {
            throw Refusal::invalid('Choose a room of this property.', ['room_id']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $file = null;

        if ($photo !== null && $photo !== '') {
            try {
                $file = $this->storeFile->execute(new FileUpload($property, $actor, self::PHOTO_PURPOSE, 'lost-found', $id, $photo, new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Sensitive, false), $photoName));
            } catch (FileRejected $e) {
                throw Refusal::invalid($e->getMessage(), ['photo']);
            }
        }

        $this->transactions->run(function () use ($property, $actor, $id, $description, $roomId, $place, $storedAt, $file): void {
            $number = $this->numbers->next($property, 'LF');
            $this->items->add($property, [
                'id' => $id, 'number' => $number, 'description' => $description, 'room_id' => $roomId, 'place' => $place, 'found_business_date' => $this->businessDate->current($property)->toString(),
                'found_by' => $actor, 'photo_file_id' => $file?->id, 'stored_at' => $storedAt,
            ], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'lost_found.recorded', 'lost_found_item', $id, null, ['number' => $number, 'room_id' => $roomId, 'place' => $place, 'stored_at' => $storedAt, 'has_photo' => $file !== null]));
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.lost_found.recorded', $id, 1, ['item_id' => $id, 'number' => $number, 'room_id' => $roomId, 'actor_id' => $actor]));
        });

        return $this->items->find($property, $id) ?? throw Refusal::notFound('Item not found.');
    }

    /** The guest or person it was given back to is named, with a note; only someone who handles lost property may do it. @return array<string, mixed> */
    public function markReturned(PropertyId $property, string $actorId, string $itemId, string $returnedTo, ?string $note, int $lock): array
    {
        $returnedTo = trim($returnedTo);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($returnedTo === '' || mb_strlen($returnedTo) > 100 || ($note !== null && mb_strlen($note) > 300)) {
            throw Refusal::invalid('Say who it was given to (at most 100 characters); a note is at most 300.', ['returned_to', 'note']);
        }

        return $this->close($property, $actorId, $itemId, 'returned', $returnedTo, $note, $lock);
    }

    /** Disposed of, donated or handed to the authorities: the reason is the record. @return array<string, mixed> */
    public function markDisposed(PropertyId $property, string $actorId, string $itemId, string $reason, int $lock): array
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('Say what was done with it and why, in at most 300 characters.', ['reason']);
        }

        return $this->close($property, $actorId, $itemId, 'disposed', null, $reason, $lock);
    }

    public function photo(PropertyId $property, string $actorId, string $itemId): FileContent
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::MANAGE_PERMISSION) && ! $this->may($property, $actorId, self::RECORD_PERMISSION)) {
            throw Refusal::forbidden('This person may not see found items.');
        }

        $item = $this->items->find($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');

        if ($item['photo_file_id'] === null) {
            throw Refusal::notFound('This item has no photo.');
        }

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->permissions->allowsInProperty($actorId, LostFoundService::MANAGE_PERMISSION, $this->property) || $this->permissions->allowsInProperty($actorId, LostFoundService::RECORD_PERMISSION, $this->property);
            }
        };

        try {
            return $this->downloadFile->execute($property, $item['photo_file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The photo is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see found items.');
        }
    }

    /** @return array<string, mixed> */
    private function close(PropertyId $property, string $actorId, string $itemId, string $status, ?string $returnedTo, ?string $note, int $lock): array
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::MANAGE_PERMISSION)) {
            throw Refusal::forbidden('This person may not return or dispose of found items.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $itemId, $status, $returnedTo, $note, $lock): void {
            $item = $this->items->find($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');

            if ($item['status'] !== 'stored') {
                throw Refusal::stateConflict('This item was already '.$item['status'].'.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->items->close($property, $item['id'], $lock, $status, $returnedTo, $note, $actor, $now)) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            if ($item['photo_file_id'] !== null) {
                $anchor = new DateTimeImmutable($this->businessDate->current($property)->toString().' 00:00:00', new DateTimeZone('UTC'));
                $this->files->setExpiryOnce($property, $item['photo_file_id'], $this->retention->expiryFor($property, self::RETENTION_CATEGORY, $anchor));
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'lost_found.'.$status, 'lost_found_item', $item['id'], ['status' => 'stored'], ['status' => $status, 'number' => $item['number'], 'returned_to' => $returnedTo], $note));
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.lost_found.'.$status, $item['id'], 1, ['item_id' => $item['id'], 'number' => $item['number'], 'actor_id' => $actor]));
        });

        return $this->items->find($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    private function authorizeAny(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, self::MANAGE_PERMISSION) && ! $this->may($property, $actorId, self::RECORD_PERMISSION) && ! $this->may($property, $actorId, HousekeepingService::VIEW_PERMISSION)) {
            throw Refusal::forbidden('This person may not see found items.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
