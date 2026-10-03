<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\GuestDesk\SelfCheckInDesk;
use App\Modules\GuestExperience\Domain\CheckInWindow;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Privacy\ConsentLedger;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a guest does on the page a link opens (FR-GST-002, -003, -004, -005, -007): reads the privacy notice and agrees to it, fills in the details, photographs the identity document, signs, says whether the deposit
 * was paid by QRIS, and sends it. Nothing is checked in by this: what the guest sends waits in the front office queue for a receptionist, and until then the room is not touched. The details are kept sealed, the
 * identity photo and the signature as private files that expire the day after the departure, and the agreement with the version of the notice and the moment it was given. Once verified the guest sees the room
 * number and how to collect the key.
 */
final readonly class SelfCheckInService
{
    public const ID_TYPES = ['ktp', 'passport', 'sim', 'kitas', 'other'];

    public const PHOTO_PURPOSE = 'guest.identity';

    public const SIGNATURE_PURPOSE = 'guest.signature';

    public const PHOTO_MAX_BYTES = 5_242_880;

    public const SIGNATURE_MAX_BYTES = 262_144;

    public const CONSENT_PURPOSE = 'guest_registration';

    public function __construct(
        private SelfCheckInStore $store,
        private SelfCheckInDesk $desk,
        private PrivacyNoticeService $notices,
        private GuestTokens $tokens,
        private PropertyProfileReader $profile,
        private BusinessDateProvider $businessDate,
        private StoreFile $storeFile,
        private FieldCipher $cipher,
        private ConsentLedger $consents,
        private SystemActors $actors,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * The link a token opens, if it is good: well formed, known, not withdrawn and not expired. The answer is the same for all the ways it can fail.
     *
     * @return array{id: string, property: PropertyId, kind: string, reservation_id: string|null, expires_at: string}|null
     */
    public function resolve(string $token): ?array
    {
        if (! $this->tokens->wellFormed($token)) {
            return null;
        }

        $link = $this->store->linkByHash($this->tokens->hash($token));

        if ($link === null || $link['revoked_at'] !== null || $this->at($link['expires_at']) <= $this->clock->nowUtc()) {
            return null;
        }

        return ['id' => (string) $link['id'], 'property' => PropertyId::fromString((string) $link['property_id']), 'kind' => (string) $link['kind'], 'reservation_id' => $link['reservation_id'], 'expires_at' => $this->at($link['expires_at'])->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * What the page shows: the form, or where the registration stands.
     *
     * @param  array<string, mixed>  $link  as `resolve` answers
     * @return array<string, mixed>
     */
    public function open(array $link): array
    {
        /** @var PropertyId $property */
        $property = $link['property'];
        $base = ['hotel' => $this->profile->nameOf($property) ?? '', 'state' => 'unavailable', 'expires_at' => $link['expires_at']];

        if ($link['kind'] === 'lobby') {
            return [...$base, 'state' => 'lobby'];
        }

        $arrival = $this->desk->arrival($property, (string) $link['reservation_id']);

        if ($arrival === null) {
            return $base;
        }

        $checkin = $this->store->checkinOfReservation($property, $arrival['reservation_id']);

        if ($checkin !== null && $checkin['status'] === 'verified') {
            return [...$base, 'state' => 'verified', 'room_number' => $checkin['room_number'], 'arrival' => $arrival['arrival'], 'departure' => $arrival['departure'], 'key' => ['id' => (string) config('guest.checkin.key_instructions.id'), 'en' => (string) config('guest.checkin.key_instructions.en'), 'note' => $checkin['key_note']]];
        }

        if ($checkin !== null && $checkin['status'] === 'submitted') {
            return [...$base, 'state' => 'waiting', 'submitted_at' => $this->at($checkin['submitted_at'])->format('Y-m-d\TH:i:s\Z')];
        }

        if ($checkin !== null && $checkin['link_id'] === $link['id']) {
            return [...$base, 'state' => 'rejected', 'reason' => $checkin['reject_reason']];
        }

        $window = CheckInWindow::of($arrival, $this->businessDate->current($property)->toString(), (int) config('guest.checkin.open_days_before'));

        if ($window['state'] === CheckInWindow::TOO_EARLY) {
            return [...$base, 'state' => 'too_early', 'opens_on' => $window['opens_on'], 'arrival' => $arrival['arrival']];
        }

        if ($window['state'] !== CheckInWindow::OPEN) {
            return $base;
        }

        $due = max(0, $arrival['deposit_required_minor'] - $arrival['deposit_held_minor']);

        return [
            ...$base, 'state' => 'form',
            'reservation' => ['number' => $arrival['number'], 'guest_name' => $arrival['guest_name'], 'arrival' => $arrival['arrival'], 'departure' => $arrival['departure'], 'nights' => $arrival['nights'], 'adults' => $arrival['adults'], 'children' => $arrival['children'], 'max_adults' => $arrival['max_adults'], 'max_children' => $arrival['max_children'], 'room_type' => $arrival['room_type']],
            'deposit' => ['currency' => $arrival['currency'], 'required_minor' => $arrival['deposit_required_minor'], 'held_minor' => $arrival['deposit_held_minor'], 'due_minor' => $due, 'instructions' => ['id' => (string) config('guest.checkin.qris_instructions.id'), 'en' => (string) config('guest.checkin.qris_instructions.en')]],
            'notice' => ['version' => $this->notices->current($property)['version'], 'body_id' => $this->notices->current($property)['body_id'], 'body_en' => $this->notices->current($property)['body_en']],
            'id_types' => self::ID_TYPES,
        ];
    }

    /**
     * The guest sends the form.
     *
     * @param  array<string, mixed>  $link  as `resolve` answers
     * @param  array<string, mixed>  $in  the fields of the form, the photo and the signature as bytes
     * @return array<string, mixed> the page after sending
     */
    public function submit(array $link, array $in): array
    {
        /** @var PropertyId $property */
        $property = $link['property'];

        if ($link['kind'] !== 'reservation') {
            throw Refusal::notFound('This link does not work.');
        }

        $view = $this->open($link);

        if ($view['state'] !== 'form') {
            throw Refusal::stateConflict($view['state'] === 'waiting' || $view['state'] === 'verified' ? 'You have sent the details already.' : 'This link cannot be used now.');
        }

        $reservation = $view['reservation'];
        $data = $this->validated($in, $reservation);
        $notice = $this->notices->current($property);

        if ((int) ($in['notice_version'] ?? -1) !== $notice['version']) {
            throw Refusal::stateConflict('The privacy notice changed. Read it again and agree to send.');
        }

        if (($in['agree'] ?? false) !== true) {
            throw Refusal::invalid('Agree to the privacy notice to send your details.', ['agree']);
        }

        $locale = in_array($in['locale'] ?? 'id', ['id', 'en'], true) ? (string) $in['locale'] : 'id';
        $deposit = $view['deposit'];
        $claimed = ($in['deposit_claimed'] ?? false) === true && $deposit['due_minor'] > 0;
        $reference = isset($in['deposit_reference']) && trim((string) $in['deposit_reference']) !== '' ? mb_substr(trim((string) $in['deposit_reference']), 0, 60) : null;
        $actor = $this->actors->guestSelfService($property, []);
        $id = $this->ids->next();
        $expires = (new DateTimeImmutable($reservation['departure'].' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day');
        $photo = $this->keep($property, $actor, $id, self::PHOTO_PURPOSE, (string) $in['photo'], ['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, 'photo', $in['photo_name'] ?? null, $expires);
        $signature = $this->keep($property, $actor, $id, self::SIGNATURE_PURPOSE, (string) $in['signature'], ['image/png'], self::SIGNATURE_MAX_BYTES, 'signature', 'signature.png', $expires);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $link, $id, $data, $reservation, $notice, $locale, $claimed, $deposit, $reference, $photo, $signature, $now): void {
            if ($this->store->checkinOfReservation($property, (string) $link['reservation_id']) !== null && $this->store->checkinOfReservation($property, (string) $link['reservation_id'])['status'] !== 'rejected') {
                throw Refusal::stateConflict('You have sent the details already.');
            }

            $added = $this->store->addCheckin($property, [
                'id' => $id, 'link_id' => $link['id'], 'reservation_id' => $link['reservation_id'], 'status' => 'submitted', 'data' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
                'adults' => $data['adults'], 'children' => $data['children'], 'id_photo_file_id' => $photo, 'signature_file_id' => $signature, 'notice_version' => $notice['version'], 'notice_digest' => $notice['digest'], 'consent_locale' => $locale, 'consented_at' => $now,
                'deposit_method' => $claimed ? 'qris' : null, 'deposit_amount_minor' => $claimed ? $deposit['due_minor'] : null, 'deposit_reference' => $claimed ? $reference : null,
            ], $now);

            if (! $added) {
                throw Refusal::stateConflict('You have sent the details already.');
            }

            $this->consents->record($property, $actor, 'self_checkin', $id, self::CONSENT_PURPOSE, (string) $notice['version'], true, $notice['digest']);
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest.self_checkin.submitted', 'guest_checkin', $id, null, ['reservation_id' => $link['reservation_id'], 'notice_version' => $notice['version'], 'deposit_claimed' => $claimed, 'files' => 2]));
            $this->outbox->publish(new OutboxEvent($property, 'guest.selfcheckin.submitted', $id, 1, ['checkin_id' => $id, 'reservation_id' => $link['reservation_id'], 'reservation_number' => $reservation['number'], 'deposit_claimed' => $claimed]));
        });

        return $this->open($link);
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  array<string, mixed>  $reservation
     * @return array{full_name: string, nationality: string, id_type: string, id_number: string, id_valid_until: string|null, visa_number: string|null, address: string, phone: string|null, email: string|null, adults: int, children: int}
     */
    private function validated(array $in, array $reservation): array
    {
        $text = static fn (string $key, int $min, int $max): ?string => (($v = trim((string) ($in[$key] ?? ''))) !== '' && mb_strlen($v) >= $min && mb_strlen($v) <= $max) ? $v : null;
        $optional = static fn (string $key, int $max): ?string => (($v = trim((string) ($in[$key] ?? ''))) === '') ? null : (mb_strlen($v) <= $max ? $v : '');
        $errors = [];

        $full = $text('full_name', 2, 150) ?? $errors[] = 'full_name';
        $nationality = strtoupper(trim((string) ($in['nationality'] ?? '')));
        preg_match('/^[A-Z]{2}$/D', $nationality) === 1 || $errors[] = 'nationality';
        $idType = (string) ($in['id_type'] ?? '');
        in_array($idType, self::ID_TYPES, true) || $errors[] = 'id_type';
        $idNumber = $text('id_number', 3, 40) ?? $errors[] = 'id_number';
        $address = $text('address', 5, 500) ?? $errors[] = 'address';
        $until = $optional('id_valid_until', 10);
        $visa = $optional('visa_number', 40);
        $phone = $optional('phone', 30);
        $email = $optional('email', 150);

        if ($until !== null && (($d = DateTimeImmutable::createFromFormat('!Y-m-d', $until)) === false || $d->format('Y-m-d') !== $until)) {
            $errors[] = 'id_valid_until';
        }

        $visa === '' && $errors[] = 'visa_number';
        $phone === '' && $errors[] = 'phone';
        ($email === '' || ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) && $errors[] = 'email';

        $adults = (int) ($in['adults'] ?? 0);
        $children = (int) ($in['children'] ?? -1);

        if ($adults < 1 || $adults > 40 || ($reservation['max_adults'] !== null && $adults > $reservation['max_adults'])) {
            $errors[] = 'adults';
        }

        if ($children < 0 || $children > 40 || ($reservation['max_children'] !== null && $children > $reservation['max_children'])) {
            $errors[] = 'children';
        }

        if (! isset($in['photo']) || $in['photo'] === '') {
            $errors[] = 'photo';
        }

        if (! isset($in['signature']) || $in['signature'] === '') {
            $errors[] = 'signature';
        }

        if ($errors !== []) {
            throw Refusal::invalid('Check the details you entered.', array_values(array_filter($errors, 'is_string')));
        }

        return ['full_name' => (string) $full, 'nationality' => $nationality, 'id_type' => $idType, 'id_number' => (string) $idNumber, 'id_valid_until' => $until, 'visa_number' => $visa, 'address' => (string) $address, 'phone' => $phone, 'email' => $email, 'adults' => $adults, 'children' => $children];
    }

    /** @param list<string> $mimes */
    private function keep(PropertyId $property, string $actor, string $ownerId, string $purpose, string $bytes, array $mimes, int $max, string $field, mixed $name, DateTimeImmutable $expires): string
    {
        try {
            return $this->storeFile->execute(new FileUpload($property, $actor, $purpose, 'self_checkin', $ownerId, $bytes, new FilePolicy($mimes, $max, FileSensitivity::Sensitive, false), is_string($name) ? $name : null, $expires))->id;
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), [$field]);
        }
    }

    private function at(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
