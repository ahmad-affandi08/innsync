<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\GuestDesk\OnlineBookingDesk;
use App\Modules\Property\Application\Catalog\RoomPhotoReader;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Privacy\ConsentLedger;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * What a guest does on the hotel's own booking page: looks at what can be sold and at what price, and sends a request. The request becomes a tentative reservation that staff confirm; nothing is charged,
 * the guest pays at the hotel. The guest agrees to the privacy notice first, and the agreement is recorded with the version of the notice. The page can be used by anyone, so it is limited in how fast it can
 * be used, has a field only a program fills in, and refuses a person who already has several requests waiting.
 */
final readonly class OnlineBookingService
{
    public const CONSENT_PURPOSE = 'online_booking';

    public const PENDING_LIMIT = 3;

    /** A request sent sooner than this after the page was opened was not filled in by a person. */
    public const MIN_FILL_SECONDS = 3;

    /** A page left open longer than this has to be opened again. */
    public const MAX_AGE_SECONDS = 21_600;

    public function __construct(
        private OnlineBookingStore $store,
        private OnlineBookingDesk $desk,
        private PrivacyNoticeService $notices,
        private PropertyProfileReader $profile,
        private ConsentLedger $consents,
        private EmailNotifier $mail,
        private RoomPhotoReader $photos,
        private FieldCipher $cipher,
        private Clock $clock,
    ) {}

    /** The property a booking address names when it takes bookings; null for anything else, and the answer is the same whatever the reason. */
    public function resolve(string $propertyId): ?PropertyId
    {
        return $this->store->bookable($propertyId);
    }

    /** @return array{content: string, sha256: string}|null a room photo for the booking page; none for an id that is not a photo of this property */
    public function photo(PropertyId $property, string $photoId, bool $thumb): ?array
    {
        return preg_match('/^[0-9A-Za-z]{26}$/', $photoId) === 1 ? $this->photos->picture($property, strtolower($photoId), $thumb) : null;
    }

    /** @return array<string, mixed> */
    public function page(PropertyId $property): array
    {
        $s = $this->store->settings($property) ?? [];
        $notice = $this->privacy($property);

        return [
            'hotel' => $this->profile->nameOf($property) ?? '',
            'notice' => $s['notice'] ?? null,
            'max_nights' => (int) ($s['max_nights'] ?? 14),
            'today' => $this->desk->today($property),
            'horizon_days' => $this->desk->horizonDays($property),
            'privacy' => ['version' => $notice['version'], 'body_id' => $notice['body_id'], 'body_en' => $notice['body_en']],
            'form_token' => $this->formToken($property),
        ];
    }

    /**
     * Proof of when the page was opened, signed with the key of the installation and bound to this property: a program that posts the form without opening the page has none, and one that opens it and posts
     * at once is too fast to be a person. It carries no personal data.
     */
    public function formToken(PropertyId $property): string
    {
        $at = $this->clock->nowUtc()->getTimestamp();

        return $at.'.'.$this->cipher->blindIndex('guest.online_booking.form', $property->toString().'|'.$at);
    }

    private function assertPersonPace(PropertyId $property, ?string $token): void
    {
        if ($token === null || preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/D', $token, $m) !== 1 || ! hash_equals($this->cipher->blindIndex('guest.online_booking.form', $property->toString().'|'.$m[1]), $m[2])) {
            throw Refusal::invalid('This request cannot be sent.', ['website']);
        }

        $age = $this->clock->nowUtc()->getTimestamp() - (int) $m[1];

        if ($age < self::MIN_FILL_SECONDS) {
            throw Refusal::invalid('This request cannot be sent.', ['website']);
        }

        if ($age > self::MAX_AGE_SECONDS) {
            throw Refusal::stateConflict('This page has been open too long. Reload it and send the request again.');
        }
    }

    /** @return array{currency: string|null, nights: int, offers: list<array<string, mixed>>} */
    public function search(PropertyId $property, string $arrival, string $departure, int $adults, int $children): array
    {
        $s = $this->store->settings($property) ?? throw Refusal::notFound('Not found.');
        $nights = $this->nights($property, $arrival, $departure, (int) $s['max_nights']);
        $this->party($adults, $children);
        $offers = $this->desk->offers($property, (string) $s['rate_plan_id'], $arrival, $departure, $adults, $children);

        return ['currency' => $offers[0]['currency'] ?? null, 'nights' => $nights, 'offers' => $offers];
    }

    /**
     * @param  array{arrival: string, departure: string, adults: int, children: int, room_type_id: string, name: string, phone: string|null, email: string|null, notes: string|null, agree: bool, notice_version: int, key: string, website: string|null, form_token?: string|null}  $in
     * @return array{number: string, status: string, total_minor: int, currency: string, arrival: string, departure: string, emailed: bool}
     */
    public function reserve(PropertyId $property, array $in, string $locale): array
    {
        $s = $this->store->settings($property) ?? throw Refusal::notFound('Not found.');

        if (trim((string) ($in['website'] ?? '')) !== '') {
            throw Refusal::invalid('This request cannot be sent.', ['website']);
        }

        $this->assertPersonPace($property, $in['form_token'] ?? null);
        $nights = $this->nights($property, $in['arrival'], $in['departure'], (int) $s['max_nights']);
        $this->party($in['adults'], $in['children']);
        $name = trim($in['name']);
        $phone = $in['phone'] === null || trim($in['phone']) === '' ? null : trim($in['phone']);
        $email = $in['email'] === null || trim($in['email']) === '' ? null : mb_strtolower(trim($in['email']));
        $notes = $in['notes'] === null || trim($in['notes']) === '' ? null : trim($in['notes']);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw Refusal::invalid('Give your name.', ['name']);
        }

        if ($phone === null && $email === null) {
            throw Refusal::invalid('Give a phone number or an email address the hotel can reach you on.', ['phone', 'email']);
        }

        if ($phone !== null && preg_match('/^[0-9+\-() ]{6,30}$/', $phone) !== 1) {
            throw Refusal::invalid('Give a phone number of digits, spaces and + - ( ).', ['phone']);
        }

        if ($email !== null && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw Refusal::invalid('Give a valid email address.', ['email']);
        }

        if ($notes !== null && mb_strlen($notes) > 300) {
            throw Refusal::invalid('The request is at most 300 characters.', ['notes']);
        }

        $notice = $this->privacy($property);

        if (! $in['agree']) {
            throw Refusal::invalid('Agree to the privacy notice to send your request.', ['agree']);
        }

        if ($in['notice_version'] !== $notice['version']) {
            throw Refusal::stateConflict('The privacy notice changed. Read it again and agree to send.');
        }

        $account = (string) ($s['actor_user_id'] ?? '');

        if ($account === '') {
            throw Refusal::notFound('Not found.');
        }

        if ($this->desk->recentForContact($property, $account, $email, $phone, 24) >= self::PENDING_LIMIT) {
            throw Refusal::stateConflict('too_many');
        }

        $booked = $this->desk->book($property, $account, ['name' => $name, 'phone' => $phone, 'email' => $email], $in['arrival'], $in['departure'], $in['adults'], $in['children'], strtolower($in['room_type_id']), (string) $s['rate_plan_id'], $notes === null ? '[Online booking]' : '[Online booking] '.$notes, $in['key']);

        // The same request sent twice (a slow connection, a second tap) is the same reservation; it is agreed to and announced once.
        if ($this->consents->isGranted($property, 'reservation', $booked['reservation_id'], self::CONSENT_PURPOSE)) {
            return ['number' => $booked['number'], 'status' => $booked['status'], 'total_minor' => $booked['total_minor'], 'currency' => $booked['currency'], 'arrival' => $in['arrival'], 'departure' => $in['departure'], 'emailed' => $email !== null];
        }

        try {
            $this->consents->record($property, $account, 'reservation', $booked['reservation_id'], self::CONSENT_PURPOSE, 'v'.$notice['version'], true, 'web');
        } catch (Throwable $e) {
            report($e);
        }

        $emailed = $this->announce($property, $s, $booked, $name, $phone, $email, $notes, $in, $nights, $locale);

        return ['number' => $booked['number'], 'status' => $booked['status'], 'total_minor' => $booked['total_minor'], 'currency' => $booked['currency'], 'arrival' => $in['arrival'], 'departure' => $in['departure'], 'emailed' => $emailed];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $booked
     * @param  array<string, mixed>  $in
     */
    private function announce(PropertyId $property, array $settings, array $booked, string $name, ?string $phone, ?string $email, ?string $notes, array $in, int $nights, string $locale): bool
    {
        $hotel = $this->profile->nameOf($property) ?? '';
        $emailed = false;
        $previous = app()->getLocale();
        app()->setLocale(in_array($locale, ['id', 'en'], true) ? $locale : 'id');

        try {
            $vars = ['hotel' => $hotel, 'number' => $booked['number'], 'arrival' => $in['arrival'], 'departure' => $in['departure'], 'nights' => $nights, 'adults' => $in['adults'], 'children' => $in['children']];

            if ($email !== null) {
                $emailed = $this->mail->notify($email, __('booking.guest_subject', $vars), __('booking.guest_body', $vars + ['name' => $name, 'notice' => (string) ($settings['notice'] ?? '')]));
            }

            if (($settings['notify_email'] ?? null) !== null) {
                $this->mail->notify((string) $settings['notify_email'], __('booking.hotel_subject', $vars), __('booking.hotel_body', $vars + ['name' => $name, 'phone' => $phone ?? '-', 'email' => $email ?? '-', 'notes' => $notes ?? '-']));
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            app()->setLocale($previous);
        }

        return $emailed;
    }

    /** The notice the guest agrees to: the hotel's own when it has written one, otherwise the baseline for booking, recorded as version 0. @return array{version: int, body_id: string, body_en: string} */
    private function privacy(PropertyId $property): array
    {
        $own = $this->notices->current($property);

        if ($own['version'] > 0) {
            return ['version' => $own['version'], 'body_id' => $own['body_id'], 'body_en' => $own['body_en']];
        }

        return ['version' => 0, 'body_id' => (string) config('guest.online_booking.privacy_baseline.id'), 'body_en' => (string) config('guest.online_booking.privacy_baseline.en')];
    }

    private function nights(PropertyId $property, string $arrival, string $departure, int $max): int
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $arrival, new DateTimeZone('UTC'));
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $departure, new DateTimeZone('UTC'));

        if ($a === false || $d === false || $a->format('Y-m-d') !== $arrival || $d->format('Y-m-d') !== $departure) {
            throw Refusal::invalid('Choose the dates.', ['arrival', 'departure']);
        }

        $today = new DateTimeImmutable($this->desk->today($property), new DateTimeZone('UTC'));
        $nights = (int) $a->diff($d)->format('%r%a');

        if ($a < $today) {
            throw Refusal::invalid('Choose an arrival date that is not in the past.', ['arrival']);
        }

        if ($nights < 1 || $nights > $max) {
            throw Refusal::invalid("Choose a stay of 1 to {$max} nights.", ['departure']);
        }

        if ((int) $today->diff($a)->format('%r%a') > $this->desk->horizonDays($property)) {
            throw Refusal::invalid('This date is too far ahead to book online.', ['arrival']);
        }

        return $nights;
    }

    private function party(int $adults, int $children): void
    {
        if ($adults < 1 || $adults > 10 || $children < 0 || $children > 10) {
            throw Refusal::invalid('Give the number of adults (1 to 10) and children (0 to 10).', ['adults', 'children']);
        }
    }
}
