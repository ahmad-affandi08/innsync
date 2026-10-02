<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Inventory\InventoryRepository;
use App\Modules\FrontOffice\Application\Inventory\RoomBlockRepository;
use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Modules\FrontOffice\Domain\Reservations\ReservationRuleViolation;
use App\Modules\FrontOffice\Domain\Stays\GuestProfile;
use App\Modules\FrontOffice\Domain\Stays\IdType;
use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Modules\FrontOffice\Domain\Stays\StayRuleViolation;
use App\Modules\FrontOffice\Domain\Stays\StayStatus;
use App\Modules\Housekeeping\Application\RoomHandover;
use App\Modules\Housekeeping\Application\RoomReadiness;
use App\Modules\Laundry\Application\LaundryLiability;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
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
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Privacy\PiiAccessAudit;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Registration, check-in and check-out (FR-FO-010 to FR-FO-016). A guest arrives on or after the arrival date by the
 * business date, into an active room of the booked type that is free and not blocked; one stay per reservation and one
 * in-house stay per room are also enforced by the database. Check-out settles the folios, completes the reservation
 * and starts the retention clock of the identity photo (BR-009).
 */
final readonly class StayService
{
    public const MANAGE_PERMISSION = 'front-office.stay.manage';

    public const VIEW_PERMISSION = 'front-office.stay.view';

    public const IDENTITY_PERMISSION = 'front-office.guest-identity.view';

    public const PHOTO_PURPOSE = 'guest.identity';

    public const PHOTO_MAX_BYTES = 5_242_880;

    private const RETENTION_CATEGORY = 'guest_identity_document';

    public function __construct(
        private StayRepository $stays,
        private RegistrationCardRepository $cards,
        private GuestRepository $guests,
        private ReservationRepository $reservations,
        private InventoryRepository $inventory,
        private RoomBlockRepository $blocks,
        private RoomCatalogReader $rooms,
        private RoomReadiness $readiness,
        private RoomHandover $handover,
        private LaundryLiability $laundry,
        private FolioRepository $folioStore,
        private FolioService $folios,
        private BusinessDateProvider $businessDate,
        private RetentionPolicies $retention,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private StoredFileRepository $files,
        private PermissionChecker $permissions,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private PiiAccessAudit $piiAccess,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    // ---- reads ----

    /**
     * Rooms the guest of this reservation can be given today: active, of the booked type, free and not blocked. A room that
     * housekeeping has not made ready is listed but marked, because the front desk may want to know when it will be.
     *
     * @return list<array{id: string, number: string, floor: ?string, ready: bool}>
     */
    public function availableRooms(PropertyId $property, string $actorId, string $reservationId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $reservation = $this->reservations->find($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
        $today = $this->businessDate->current($property);
        $result = [];
        $statuses = $this->readiness->statuses($property);

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($room->roomTypeId !== $reservation->roomTypeId || $this->isUnavailable($property, $room->id, $today, $reservation)) {
                continue;
            }

            $result[] = ['id' => $room->id, 'number' => $room->number, 'floor' => $room->floor, 'ready' => ($statuses[$room->id] ?? 'ready') === 'ready'];
        }

        usort($result, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return $result;
    }

    /**
     * Earlier registrations with the same identity document, so the desk can reuse the guest's history (FR-FO-015).
     * Looking up identity is itself access to personal data and is audited.
     *
     * @return list<array{guest_id: string, full_name: string, stays: int, last_stay: ?string}>
     */
    public function previousGuests(PropertyId $property, string $actorId, string $reservationId, string $idType, string $idNumber): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $reservation = $this->reservations->find($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');

        if (IdType::tryFrom($idType) === null || trim($idNumber) === '') {
            throw Refusal::invalid('Choose an identity type and enter its number.', ['id_type', 'id_number']);
        }

        $matches = $this->guests->previousWithDocument($property, $idType, $idNumber);

        if ($matches !== []) {
            $this->piiAccess->record($property, strtolower($actorId), 'reservation', $reservation->id, 'Looked up earlier stays by identity document', ['full_name', 'id_number']);
        }

        return $matches;
    }

    /**
     * A stay with its guest. The identity number and address are shown only to people allowed to see them, and each such
     * view is audited (BR-009); everyone else sees the number masked.
     *
     * @return array<string, mixed>
     */
    public function view(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        return $this->describe($property, $actorId, $stay);
    }

    /** @return array<string, mixed>|null */
    public function forReservation(PropertyId $property, string $actorId, string $reservationId): ?array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $stay = $this->stays->findByReservation($property, strtolower($reservationId));

        return $stay === null ? null : $this->describe($property, $actorId, $stay);
    }

    /** @return list<array<string, mixed>> guests in the house now, identity masked */
    public function inHouse(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return array_map(fn (Stay $s): array => $this->describe($property, $actorId, $s, false), $this->stays->inHouse($property));
    }

    // ---- check-in ----

    /** @return array<string, mixed> the stay, plus warnings about the identity document the desk should look at */
    public function checkIn(PropertyId $property, string $actorId, CheckInRequest $request, IdempotencyKey $key): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);

        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'stay.check_in', $request->fingerprint(), strtolower($actorId)),
            fn (): array => $this->register($property, strtolower($actorId), $request),
        );

        return [...$this->view($property, $actorId, (string) $result->payload['stay_id']), 'warnings' => $result->payload['warnings']];
    }

    // ---- identity photo ----

    public function attachIdPhoto(PropertyId $property, string $actorId, string $stayId, string $contents, ?string $displayName): StoredFile
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        try {
            $stay->assertInHouse();
        } catch (StayRuleViolation $e) {
            throw Refusal::stateConflict($e->getMessage());
        }

        try {
            $file = $this->storeFile->execute(new FileUpload(
                $property, strtolower($actorId), self::PHOTO_PURPOSE, 'stay', $stay->id, $contents,
                new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Sensitive, false), $displayName,
            ));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['photo']);
        }

        $now = $this->clock->nowUtc();
        $attached = $this->transactions->run(function () use ($property, $actorId, $stay, $file): bool {
            if (! $this->stays->attachIdPhoto($property, $stay->id, $file->id, $stay->lockVersion)) {
                return false;
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'stay.id_photo.attached', 'stay', $stay->id, ['has_id_photo' => $stay->idPhotoFileId !== null], ['has_id_photo' => true, 'file_id' => $file->id]));

            return true;
        });

        if (! $attached) {
            // Lost a race with another change: the unattached photo is given an expiry now so retention erases it.
            $this->files->setExpiryOnce($property, $file->id, $now);

            throw Refusal::stateConflict('This stay changed while the photo was uploading; try again.');
        }

        if ($stay->idPhotoFileId !== null) {
            // The replaced photo is no longer needed; it expires at once and the daily purge erases it.
            $this->files->setExpiryOnce($property, $stay->idPhotoFileId, $now);
        }

        return $file;
    }

    /** The identity photo of a stay. Every read is audited by the file download, and denied without the identity permission. */
    public function idPhoto(PropertyId $property, string $actorId, string $stayId): FileContent
    {
        $this->authorize($property, $actorId, self::IDENTITY_PERMISSION);
        $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        if ($stay->idPhotoFileId === null) {
            throw Refusal::notFound('This stay has no identity photo.');
        }

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->permissions->allowsInProperty($actorId, StayService::IDENTITY_PERMISSION, $this->property);
            }
        };

        try {
            return $this->downloadFile->execute($property, $stay->idPhotoFileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The identity photo is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not view identity documents.');
        }
    }

    // ---- check-out ----

    /**
     * Ends the stay. Every folio must be settled (balance zero); open ones are closed. The reservation is completed,
     * which releases the nights of an early departure, and the identity photo's retention starts from today (BR-009).
     *
     * @return array<string, mixed>
     */
    public function checkOut(PropertyId $property, string $actorId, string $stayId, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $actor = strtolower($actorId);
        $before = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        $this->transactions->run(function () use ($property, $actor, $before, $expectedLockVersion): void {
            $reservation = $this->reservations->find($property, $before->reservationId) ?? throw Refusal::notFound('Reservation not found.');
            // Completing releases inventory, so it takes the same lock as selling it.
            $this->inventory->lockRoomType($property, $reservation->roomTypeId);
            $stay = $this->stays->find($property, $before->id) ?? throw Refusal::notFound('Stay not found.');

            if ($stay->lockVersion !== $expectedLockVersion) {
                throw Refusal::stateConflict('This stay changed after you opened it.');
            }

            try {
                $stay->assertInHouse();
                $completed = $reservation->complete();
            } catch (StayRuleViolation|ReservationRuleViolation $e) {
                throw Refusal::stateConflict($e->getMessage());
            }

            $today = $this->businessDate->current($property);
            $now = $this->clock->nowUtc();

            if ($this->laundry->activeOrdersOfStay($property, $stay->id) > 0) {
                throw Refusal::stateConflict('The guest still has laundry that has not been delivered. Deliver or cancel it before checking out.');
            }

            foreach ($this->folioStore->byReservation($property, $reservation->id) as $folio) {
                if ($folio->balance->amountMinor !== 0) {
                    throw Refusal::stateConflict(sprintf('Folio %s still has a balance of %d; settle it before checking out.', $folio->number, $folio->balance->amountMinor));
                }
            }

            foreach ($this->folioStore->byReservation($property, $reservation->id) as $folio) {
                if (! $folio->isClosed) {
                    $this->folios->close($property, $actor, $folio->id, $folio->lockVersion);
                }
            }

            if (! $this->stays->checkOut($property, $stay, $expectedLockVersion, $today, $actor, $now)
                || ! $this->reservations->saveStatus($property, $completed, $reservation->lockVersion, $actor, $now)) {
                throw Refusal::stateConflict('This stay changed after you opened it.');
            }

            $anchor = new DateTimeImmutable($today->toString().' 00:00:00', new DateTimeZone('UTC'));

            if ($stay->idPhotoFileId !== null) {
                $this->files->setExpiryOnce($property, $stay->idPhotoFileId, $this->retention->expiryFor($property, self::RETENTION_CATEGORY, $anchor));
            }

            $signature = $this->cards->find($property, $stay->id)['signature_file_id'] ?? null;

            if ($signature !== null) {
                $this->files->setExpiryOnce($property, $signature, $this->retention->expiryFor($property, self::RETENTION_CATEGORY, $anchor));
            }

            $this->handover->vacated($property, $stay->roomId, $stay->id, $actor);
            $kind = $stay->departureKind($today);
            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'stay.checked_out', 'stay', $stay->id,
                ['status' => 'in_house'], ['status' => 'checked_out', 'business_date' => $today->toString(), 'departure' => $kind],
            ));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.stay.checked_out', $stay->id, 1, [
                'stay_id' => $stay->id, 'reservation_id' => $reservation->id, 'room_id' => $stay->roomId,
                'business_date' => $today->toString(), 'departure' => $kind, 'actor_id' => $actor,
            ]));
        });

        return $this->view($property, $actorId, $before->id);
    }

    // ---- internals ----

    /** @return array{stay_id: string, warnings: list<string>} */
    private function register(PropertyId $property, string $actor, CheckInRequest $request): array
    {
        $roomId = strtolower($request->roomId);
        $reservation = $this->reservations->find($property, strtolower($request->reservationId)) ?? throw Refusal::notFound('Reservation not found.');

        // Check-in does not consume inventory, but it must not interleave with a booking change of the same room type.
        $this->inventory->lockRoomType($property, $reservation->roomTypeId);
        $reservation = $this->reservations->find($property, $reservation->id) ?? throw Refusal::notFound('Reservation not found.');
        $today = $this->businessDate->current($property);

        $room = $this->rooms->room($property, $roomId);

        if ($room === null || ! $room->isActive || $room->roomTypeId !== $reservation->roomTypeId) {
            throw Refusal::invalid('Choose an active room of the booked room type.', ['room_id']);
        }

        if ($this->isUnavailable($property, $roomId, $today, $reservation)) {
            throw Refusal::stateConflict('This room is occupied or out of service.');
        }

        if (! $this->readiness->isReady($property, $roomId)) {
            throw Refusal::stateConflict('Housekeeping has not made this room ready.');
        }

        $type = $this->rooms->type($property, $reservation->roomTypeId);

        if ($type !== null && ($request->adults > $type->maxAdults || $request->children > $type->maxChildren)) {
            throw Refusal::invalid('The guests exceed what this room type allows.', ['adults', 'children']);
        }

        try {
            $profile = new GuestProfile(
                $this->ids->next(), $request->fullName, $request->nationality,
                IdType::tryFrom($request->idType) ?? throw StayRuleViolation::invalidGuest('Choose a valid identity type.', 'id_type'),
                $request->idNumber,
                $request->idValidUntil === null || $request->idValidUntil === '' ? null : BusinessDate::fromString($request->idValidUntil),
                $request->visaNumber, $request->address,
            );
            $checkedIn = $reservation->checkIn($roomId, $today);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['id_valid_until']);
        } catch (StayRuleViolation $e) {
            throw $e->reasonCode === StayRuleViolation::INVALID_GUEST
                ? Refusal::invalid($e->getMessage(), $e->field === null ? [] : [$e->field])
                : Refusal::stateConflict($e->getMessage());
        }

        $now = $this->clock->nowUtc();
        $stay = new Stay($this->ids->next(), $reservation->id, $profile->id, $roomId, StayStatus::InHouse, $request->adults, $request->children, $today, $now, $reservation->stay->departure, null, null, 0);

        $this->guests->add($property, $profile, $actor, $now);

        if (! $this->stays->add($property, $stay, $actor)) {
            throw Refusal::stateConflict('This reservation already has a stay, or the room already has a guest.');
        }

        if (! $this->reservations->saveStatus($property, $checkedIn, $reservation->lockVersion, $actor, $now)) {
            throw Refusal::stateConflict('This reservation changed after you opened it.');
        }

        if ($this->folioStore->byReservation($property, $reservation->id) === []) {
            $this->folios->open($property, $actor, $reservation->id);
        }

        $this->audit->record(new AuditEntry(
            $property->toString(), $actor, 'stay.checked_in', 'stay', $stay->id,
            ['reservation_status' => $reservation->status->value],
            ['reservation_status' => 'checked_in', 'room_id' => $roomId, 'business_date' => $today->toString(), 'adults' => $stay->adults, 'children' => $stay->children],
        ));
        $this->piiAccess->record($property, $actor, 'stay', $stay->id, 'Registered the guest at check-in', ['full_name', 'id_number', 'address']);
        $this->outbox->publish(new OutboxEvent($property, 'frontoffice.stay.checked_in', $stay->id, 1, [
            'stay_id' => $stay->id, 'reservation_id' => $reservation->id, 'room_id' => $roomId, 'business_date' => $today->toString(),
            'expected_departure' => $stay->expectedDeparture->toString(), 'actor_id' => $actor,
        ]));

        return ['stay_id' => $stay->id, 'warnings' => $profile->documentWarnings($today, $reservation->stay->departure)];
    }

    private function isUnavailable(PropertyId $property, string $roomId, BusinessDate $today, Reservation $reservation): bool
    {
        if ($this->stays->roomIsOccupied($property, $roomId)) {
            return true;
        }

        // Blocks cover the nights of the stay; the last night is the one before departure.
        return $this->blocks->overlapping($property, $roomId, $today->toString(), $reservation->stay->departure->previous()->toString()) !== [];
    }

    /** @return array<string, mixed> */
    private function describe(PropertyId $property, string $actorId, Stay $stay, bool $audited = true): array
    {
        $guest = $this->guests->find($property, $stay->guestId) ?? throw Refusal::notFound('Guest not found.');
        $mayReadIdentity = $this->permissions->allowsInProperty($actorId, self::IDENTITY_PERMISSION, $property);

        if ($mayReadIdentity && $audited) {
            $this->piiAccess->record($property, strtolower($actorId), 'stay', $stay->id, 'Viewed the guest registration', ['id_number', 'address']);
        }

        $room = $this->rooms->room($property, $stay->roomId);

        return [
            ...$stay->toArray(),
            'room_number' => $room?->number,
            'guest' => [
                'full_name' => $guest->fullName,
                'nationality' => $guest->nationality,
                'id_type' => $guest->idType->value,
                'id_number' => $mayReadIdentity && $audited ? $guest->idNumber : GuestProfile::mask($guest->idNumber),
                'id_valid_until' => $guest->idValidUntil?->toString(),
                'visa_number' => $mayReadIdentity && $audited ? $guest->visaNumber : ($guest->visaNumber === null ? null : GuestProfile::mask($guest->visaNumber)),
                'address' => $mayReadIdentity && $audited ? $guest->address : null,
                'identity_visible' => $mayReadIdentity && $audited,
            ],
        ];
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($permission === self::VIEW_PERMISSION && $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not use check-in and stays.');
        }
    }
}
