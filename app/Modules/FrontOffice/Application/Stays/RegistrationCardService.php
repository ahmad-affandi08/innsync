<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
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
use App\Shared\Application\Files\StoreFile;
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
 * The registration card of a stay (FR-FO-017): the guest's details, the room and dates, the house terms and a signature, for printing
 * and for signing on a tablet. The identity details are masked as on the stay page unless the person may read identity (and that is
 * audited by the stay view). A signed card keeps the terms as they read when it was signed and the signature image, a private file
 * that is erased on the retention schedule of identity documents after check-out; it is signed once and never changed.
 */
final readonly class RegistrationCardService
{
    public const TERMS_PERMISSION = 'front-office.registration.terms';

    public const SIGNATURE_PURPOSE = 'guest.signature';

    public const SIGNATURE_MAX_BYTES = 262_144;

    public function __construct(
        private RegistrationCardRepository $cards,
        private StayService $stays,
        private StayRepository $stayStore,
        private ReservationRepository $reservations,
        private PropertyProfileReader $profile,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{terms: array{version: int, body: string}|null, may_edit: bool} */
    public function terms(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::TERMS_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, StayService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the registration terms.');
        }

        return ['terms' => $this->cards->latestTerms($property), 'may_edit' => $this->permissions->allowsInProperty($actorId, self::TERMS_PERMISSION, $property)];
    }

    /** A new version of the house terms printed on the card. @return array{version: int, body: string} */
    public function defineTerms(PropertyId $property, string $actorId, string $body, string $reason): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::TERMS_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not write the registration terms.');
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 4000) {
            throw Refusal::invalid('Write the terms in at most 4,000 characters.', ['body']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $version = $this->transactions->run(function () use ($property, $actor, $id, $body, $reason): int {
            $version = $this->cards->addTerms($property, $id, $body, trim($reason), $actor, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'registration.terms.defined', 'registration_terms', $id, null, ['version' => $version, 'length' => mb_strlen($body)], trim($reason)));

            return $version;
        });

        return ['version' => $version, 'body' => $body];
    }

    /**
     * The card to print or sign.
     *
     * @return array<string, mixed>
     */
    public function card(PropertyId $property, string $actorId, string $stayId): array
    {
        $this->assertProperty($property);
        $stay = $this->stays->view($property, $actorId, $stayId);
        $reservation = $this->reservations->find($property, $stay['reservation_id']);
        $signed = $this->cards->find($property, $stay['id']);
        $latest = $this->cards->latestTerms($property);
        $names = $signed === null ? [] : $this->staff->namesOf($property, [$signed['recorded_by']]);

        return [
            'hotel' => $this->profile->nameOf($property) ?? '',
            'stay_id' => $stay['id'], 'reservation_number' => $reservation?->number ?? '', 'status' => $stay['status'], 'room_number' => $stay['room_number'],
            'arrival' => $stay['checked_in_date'], 'departure' => $stay['expected_departure'], 'adults' => $stay['adults'], 'children' => $stay['children'],
            'guest' => $stay['guest'],
            'rate' => $reservation === null ? null : ['nights' => count($reservation->bookedNights()), 'total_minor' => $reservation->toArray()['total_minor'], 'currency' => $reservation->toArray()['currency']],
            'terms' => $signed !== null ? ($signed['terms_body'] === null ? null : ['version' => $signed['terms_version'], 'body' => $signed['terms_body']]) : $latest,
            'signed' => $signed === null ? null : ['at' => $signed['signed_at'], 'by' => $names[$signed['recorded_by']] ?? null],
            'may_sign' => $signed === null && $stay['status'] === 'in_house' && $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property),
            'may_see_signature' => $signed !== null && $this->permissions->allowsInProperty($actorId, StayService::IDENTITY_PERMISSION, $property),
        ];
    }

    /**
     * Records the guest's signature, a PNG image, on the card of an in-house stay. Once.
     *
     * @return array<string, mixed> the card
     */
    public function sign(PropertyId $property, string $actorId, string $stayId, string $png): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, StayService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not record a signature.');
        }

        $stay = $this->stayStore->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

        if (! $stay->isInHouse()) {
            throw Refusal::stateConflict('Only a guest who is in the house signs the card.');
        }

        if ($this->cards->find($property, $stay->id) !== null) {
            throw Refusal::stateConflict('This card is already signed.');
        }

        $actor = strtolower($actorId);

        try {
            $file = $this->storeFile->execute(new FileUpload($property, $actor, self::SIGNATURE_PURPOSE, 'stay', $stay->id, $png, new FilePolicy(['image/png'], self::SIGNATURE_MAX_BYTES, FileSensitivity::Sensitive, false), 'signature.png'));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['signature']);
        }

        $latest = $this->cards->latestTerms($property);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $stay, $latest, $file, $id): void {
            if (! $this->cards->add($property, $id, $stay->id, $latest['body'] ?? null, $latest['version'] ?? null, $file->id, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This card is already signed.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'registration.signed', 'stay', $stay->id, null, ['terms_version' => $latest['version'] ?? null, 'file_id' => $file->id]));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.registration.signed', $id, 1, ['stay_id' => $stay->id, 'actor_id' => $actor]));
        });

        return $this->card($property, $actorId, $stayId);
    }

    /** The signature image: personal data, read only by those who may read identity, and every read audited. */
    public function signature(PropertyId $property, string $actorId, string $stayId): FileContent
    {
        $this->assertProperty($property);
        $signed = $this->cards->find($property, strtolower($stayId)) ?? throw Refusal::notFound('This card is not signed.');

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->permissions->allowsInProperty($actorId, StayService::IDENTITY_PERMISSION, $this->property);
            }
        };

        try {
            return $this->downloadFile->execute($property, $signed['signature_file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The signature is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not view signatures.');
        }
    }

    /** The signature file of a stay, for the check-out to start its retention; null when unsigned. */
    public function signatureFileId(PropertyId $property, string $stayId): ?string
    {
        return $this->cards->find($property, $stayId)['signature_file_id'] ?? null;
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
