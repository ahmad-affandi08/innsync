<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\GuestDesk\SelfCheckInDesk;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Application\Privacy\PiiAccessAudit;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * What a receptionist does with what guests sent (FR-GST-004, FR-GST-005, FR-GST-003): reads it, compares it with the identity document, chooses the room, and either checks the guest in or sends it back.
 * Verifying is the check-in: it is done as the receptionist, through the same front office service as at the desk, so the room, its readiness, the registration card and the deposit follow the usual rules, and
 * nothing changes before a person does it. The deposit a guest says was paid by QRIS is posted to the folio by the receptionist who has seen the money. Reading the details and the documents needs the right
 * to read identity, and each read is recorded. When it is decided the sealed details are erased from here: the stay keeps them.
 */
final readonly class SelfCheckInQueueService
{
    public function __construct(
        private SelfCheckInStore $store,
        private SelfCheckInDesk $desk,
        private DownloadFile $downloadFile,
        private FieldCipher $cipher,
        private PiiAccessAudit $piiAccess,
        private GuestAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function queue(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::CHECKIN_MANAGE, 'This person may not manage the self check-in.');
        $rows = fn (string $status, int $limit): array => array_values(array_filter(array_map(fn (array $c): ?array => $this->row($property, $c), $this->store->checkinsByStatus($property, $status, $limit))));

        return ['waiting' => $rows('submitted', 100), 'verified' => array_reverse($rows('verified', 10)), 'rejected' => array_reverse($rows('rejected', 10)), 'may_read_identity' => $this->access->may($property, $actorId, GuestAccess::IDENTITY_VIEW)];
    }

    /**
     * One submission with its details, for the receptionist to compare with the document. Reading it is recorded.
     *
     * @return array<string, mixed>
     */
    public function detail(PropertyId $property, string $actorId, string $id): array
    {
        $checkin = $this->readable($property, $actorId, $id);
        $row = $this->row($property, $checkin) ?? throw Refusal::notFound('Self check-in not found.');
        $data = null;

        if ($checkin['status'] === 'submitted' && $checkin['data'] !== null) {
            $data = $this->data($checkin);
            $this->piiAccess->record($property, strtolower($actorId), 'guest_checkin', (string) $checkin['id'], 'Reviewed what a guest sent by self check-in, to verify it', ['full_name', 'id_number', 'address', 'id_photo', 'signature']);
        }

        return [
            ...$row, 'data' => $data, 'consent' => ['version' => (int) $checkin['notice_version'], 'at' => $this->iso($checkin['consented_at']), 'locale' => $checkin['consent_locale']],
            'rooms' => $checkin['status'] === 'submitted' ? $this->desk->rooms($property, $actorId, (string) $checkin['reservation_id']) : [], 'has_photo' => $checkin['status'] === 'submitted' && $checkin['id_photo_file_id'] !== null, 'has_signature' => $checkin['status'] === 'submitted' && $checkin['signature_file_id'] !== null,
            'lock_version' => (int) $checkin['lock_version'],
        ];
    }

    /** The identity photo the guest took. Every read is recorded by the file download. */
    public function photo(PropertyId $property, string $actorId, string $id): FileContent
    {
        $checkin = $this->readable($property, $actorId, $id);

        return $this->file($property, $actorId, $checkin['status'] === 'submitted' ? $checkin['id_photo_file_id'] : null);
    }

    public function signature(PropertyId $property, string $actorId, string $id): FileContent
    {
        $checkin = $this->readable($property, $actorId, $id);

        return $this->file($property, $actorId, $checkin['status'] === 'submitted' ? $checkin['signature_file_id'] : null);
    }

    /**
     * Checks the guest in with what they sent, into the room the receptionist chose.
     *
     * @return array<string, mixed> the submission after verifying
     */
    public function verify(PropertyId $property, string $actorId, string $id, int $lock, string $roomId, ?string $keyNote, int $depositReceivedMinor): array
    {
        $checkin = $this->readable($property, $actorId, $id);
        $keyNote = $keyNote === null || trim($keyNote) === '' ? null : trim($keyNote);

        if ($checkin['status'] !== 'submitted') {
            throw Refusal::stateConflict('This was decided already.');
        }

        if ((int) $checkin['lock_version'] !== $lock) {
            throw Refusal::stateConflict('This changed after you opened it.');
        }

        if ($keyNote !== null && mb_strlen($keyNote) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['key_note']);
        }

        if ($depositReceivedMinor < 0 || $depositReceivedMinor > 100_000_000_000) {
            throw Refusal::invalid('Enter the deposit received, or zero.', ['deposit_received']);
        }

        $actor = strtolower($actorId);
        $data = $this->data($checkin);
        $photo = $this->file($property, $actor, $checkin['id_photo_file_id']);
        $signature = $this->file($property, $actor, $checkin['signature_file_id']);
        $reservationId = (string) $checkin['reservation_id'];

        if ($depositReceivedMinor > 0) {
            $this->desk->receiveDeposit($property, $actor, $reservationId, $depositReceivedMinor, $checkin['deposit_reference'], 'selfcheckin:'.$checkin['id']);
        }

        $stay = $this->desk->checkIn($property, $actor, $reservationId, strtolower($roomId), [...$data, 'adults' => (int) $checkin['adults'], 'children' => (int) $checkin['children']], $photo->contents, $photo->file->displayName, $signature->contents, 'selfcheckin:'.$checkin['id']);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $checkin, $lock, $stay, $roomId, $keyNote, $depositReceivedMinor, $now): void {
            if (! $this->store->decide($property, (string) $checkin['id'], $lock, ['status' => 'verified', 'data' => null, 'decided_by' => $actor, 'decided_at' => $now, 'stay_id' => $stay['stay_id'], 'room_id' => strtolower($roomId), 'room_number' => $stay['room_number'], 'key_note' => $keyNote], $now)) {
                throw Refusal::stateConflict('This changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest.self_checkin.verified', 'guest_checkin', (string) $checkin['id'], ['status' => 'submitted'], ['status' => 'verified', 'stay_id' => $stay['stay_id'], 'room_number' => $stay['room_number'], 'deposit_received_minor' => $depositReceivedMinor]));
            $this->outbox->publish(new OutboxEvent($property, 'guest.selfcheckin.verified', (string) $checkin['id'], 1, ['checkin_id' => $checkin['id'], 'stay_id' => $stay['stay_id'], 'reservation_id' => $checkin['reservation_id'], 'actor_id' => $actor]));
        });

        return $this->detail($property, $actorId, $id);
    }

    /**
     * Sends it back; the guest sees the reason and the receptionist may send a new link.
     *
     * @return array<string, mixed>
     */
    public function reject(PropertyId $property, string $actorId, string $id, int $lock, string $reason): array
    {
        $checkin = $this->readable($property, $actorId, $id);
        $reason = trim($reason);

        if ($checkin['status'] !== 'submitted') {
            throw Refusal::stateConflict('This was decided already.');
        }

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('Say why, in at most 300 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $checkin, $lock, $reason, $now): void {
            if (! $this->store->decide($property, (string) $checkin['id'], $lock, ['status' => 'rejected', 'data' => null, 'decided_by' => $actor, 'decided_at' => $now, 'reject_reason' => $reason], $now)) {
                throw Refusal::stateConflict('This changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest.self_checkin.rejected', 'guest_checkin', (string) $checkin['id'], ['status' => 'submitted'], ['status' => 'rejected'], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'guest.selfcheckin.rejected', (string) $checkin['id'], 1, ['checkin_id' => $checkin['id'], 'reservation_id' => $checkin['reservation_id'], 'actor_id' => $actor]));
        });

        return $this->detail($property, $actorId, $id);
    }

    /** @return array<string, mixed> a submission the actor may read: they manage the self check-in and may read identity */
    private function readable(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, GuestAccess::CHECKIN_MANAGE, 'This person may not manage the self check-in.');

        if (! $this->access->may($property, $actorId, GuestAccess::IDENTITY_VIEW)) {
            throw Refusal::forbidden('This person may not read identity documents.');
        }

        return $this->store->checkin($property, strtolower($id)) ?? throw Refusal::notFound('Self check-in not found.');
    }

    /** @param array<string, mixed> $checkin @return array{full_name: string, nationality: string, id_type: string, id_number: string, id_valid_until: string|null, visa_number: string|null, address: string, phone: string|null, email: string|null} */
    private function data(array $checkin): array
    {
        try {
            /** @var array{full_name: string, nationality: string, id_type: string, id_number: string, id_valid_until: string|null, visa_number: string|null, address: string, phone: string|null, email: string|null} $data */
            $data = json_decode($this->cipher->open((string) $checkin['data']), true, 8, JSON_THROW_ON_ERROR);
        } catch (RuntimeException|\JsonException) {
            throw Refusal::stateConflict('The details cannot be read. Ask the guest to send them again.');
        }

        return $data;
    }

    private function file(PropertyId $property, string $actorId, mixed $fileId): FileContent
    {
        if (! is_string($fileId) || $fileId === '') {
            throw Refusal::notFound('There is nothing to show.');
        }

        $policy = new class($this->access, $property) implements FileAccessPolicy
        {
            public function __construct(private GuestAccess $access, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->access->may($this->property, $actorId, GuestAccess::CHECKIN_MANAGE) && $this->access->may($this->property, $actorId, GuestAccess::IDENTITY_VIEW);
            }
        };

        try {
            return $this->downloadFile->execute($property, $fileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The file is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not read identity documents.');
        }
    }

    /** @param array<string, mixed> $c @return array<string, mixed>|null */
    private function row(PropertyId $property, array $c): ?array
    {
        $arrival = $this->desk->arrival($property, (string) $c['reservation_id']);

        if ($arrival === null) {
            return null;
        }

        return [
            'id' => $c['id'], 'status' => $c['status'], 'reservation_id' => $c['reservation_id'], 'number' => $arrival['number'], 'guest_name' => $arrival['guest_name'], 'arrival' => $arrival['arrival'], 'departure' => $arrival['departure'], 'room_type' => $arrival['room_type'],
            'adults' => (int) $c['adults'], 'children' => (int) $c['children'], 'submitted_at' => $this->iso($c['submitted_at']), 'decided_at' => $c['decided_at'] === null ? null : $this->iso($c['decided_at']), 'room_number' => $c['room_number'], 'reject_reason' => $c['reject_reason'],
            'deposit' => ['currency' => $arrival['currency'], 'required_minor' => $arrival['deposit_required_minor'], 'held_minor' => $arrival['deposit_held_minor'], 'claimed_minor' => $c['deposit_amount_minor'] === null ? null : (int) $c['deposit_amount_minor'], 'reference' => $c['deposit_reference']],
        ];
    }

    private function iso(mixed $value): string
    {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
