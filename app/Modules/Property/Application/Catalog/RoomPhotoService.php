<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/** The photos of a room type: up to six, the first is the main one. Anyone who may see the catalog sees them; whoever manages the catalog adds, removes and orders them, and every change is audited. */
final readonly class RoomPhotoService
{
    public const MAX_PER_TYPE = 6;

    public function __construct(private RoomPhotoStore $store, private PermissionChecker $permissions, private PropertyContext $property, private IdentifierGenerator $ids, private TransactionRunner $transactions, private AuditTrail $audit) {}

    /** @return array<string, list<string>> photo ids per room type, main first */
    public function overview(PropertyId $property, string $actorId, array $roomTypeIds): array
    {
        $this->scope($property);

        return $this->store->idsByType($property, $roomTypeIds);
    }

    /** @return list<string> */
    public function add(PropertyId $property, string $actorId, string $roomTypeId, string $bytes): array
    {
        $this->manage($property, $actorId);
        $roomTypeId = strtolower($roomTypeId);
        $this->type($property, $roomTypeId);

        if (count($this->store->listFor($property, $roomTypeId)) >= self::MAX_PER_TYPE) {
            throw Refusal::invalid('A room type has at most '.self::MAX_PER_TYPE.' photos. Remove one first.', ['photo']);
        }

        $image = RoomPhotoImage::accept($bytes);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $roomTypeId, $id, $image): void {
            $this->store->add($property, $roomTypeId, $id, $image['full'], $image['thumb'], hash('sha256', $image['full']), strtolower($actorId));
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_type.photo.added', 'room_type', $roomTypeId, null, ['photo_id' => $id, 'bytes' => strlen($image['full'])]));
        });

        return $this->ids($property, $roomTypeId);
    }

    /** @return list<string> */
    public function remove(PropertyId $property, string $actorId, string $roomTypeId, string $photoId): array
    {
        $this->manage($property, $actorId);
        $roomTypeId = strtolower($roomTypeId);
        $this->type($property, $roomTypeId);

        $this->transactions->run(function () use ($property, $actorId, $roomTypeId, $photoId): void {
            if (! $this->store->remove($property, $roomTypeId, strtolower($photoId))) {
                throw Refusal::notFound('Photo not found.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_type.photo.removed', 'room_type', $roomTypeId, ['photo_id' => strtolower($photoId)], null));
        });

        return $this->ids($property, $roomTypeId);
    }

    /**
     * @param  list<string>  $orderedIds
     * @return list<string>
     */
    public function order(PropertyId $property, string $actorId, string $roomTypeId, array $orderedIds): array
    {
        $this->manage($property, $actorId);
        $roomTypeId = strtolower($roomTypeId);
        $this->type($property, $roomTypeId);
        $current = $this->ids($property, $roomTypeId);
        $wanted = array_map('strtolower', $orderedIds);

        if (count($wanted) !== count($current) || array_diff($wanted, $current) !== [] || count(array_unique($wanted)) !== count($wanted)) {
            throw Refusal::invalid('Give every photo of the room type once.', ['ids']);
        }

        if ($wanted !== $current) {
            $this->transactions->run(function () use ($property, $actorId, $roomTypeId, $wanted, $current): void {
                $this->store->reorder($property, $roomTypeId, $wanted);
                $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_type.photo.ordered', 'room_type', $roomTypeId, ['order' => $current], ['order' => $wanted]));
            });
        }

        return $wanted;
    }

    /** @return array{content: string, sha256: string}|null a photo for a signed-in person of the property */
    public function picture(PropertyId $property, string $photoId, bool $thumb): ?array
    {
        $this->scope($property);

        return $this->store->picture($property, strtolower($photoId), $thumb);
    }

    /** @return list<string> */
    private function ids(PropertyId $property, string $roomTypeId): array
    {
        return array_map(static fn (array $p): string => $p['id'], $this->store->listFor($property, $roomTypeId));
    }

    private function type(PropertyId $property, string $roomTypeId): void
    {
        if (! $this->store->typeExists($property, $roomTypeId)) {
            throw Refusal::notFound('Room type not found.');
        }
    }

    private function manage(PropertyId $property, string $actorId): void
    {
        $this->scope($property);

        if (! $this->permissions->allowsInProperty($actorId, RoomCatalogService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not change the room catalog.');
        }
    }

    private function scope(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
