<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\GuestDesk\SelfCheckInDesk;
use App\Modules\GuestExperience\Domain\CheckInWindow;
use App\Modules\GuestExperience\Domain\GuestNameMatcher;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The ways into the self check-in (FR-GST-001, FR-GST-006): a link for one reservation that a receptionist sends before the arrival, and the code at the lobby. Both carry a random token that says nothing
 * about the reservation and ends by itself; a link that expired or was withdrawn gives the same answer as one that never existed. The lobby code only asks for the reservation number and the name of the
 * guest, checked against the front office with a few tries, and then gives a short link for that one reservation.
 */
final readonly class SelfCheckInLinkService
{
    public function __construct(
        private SelfCheckInStore $store,
        private SelfCheckInDesk $desk,
        private GuestTokens $tokens,
        private BusinessDateProvider $businessDate,
        private GuestAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * Guests due soon, with how far each one has come, and the code at the lobby.
     *
     * @return array<string, mixed>
     */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::CHECKIN_MANAGE, 'This person may not manage the self check-in.');
        $now = $this->clock->nowUtc();
        $today = $this->businessDate->current($property)->toString();
        $days = max(0, (int) config('guest.checkin.arrivals_days'));
        $to = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify("+{$days} days")->format('Y-m-d');
        $rows = [];

        foreach ($this->desk->arrivals($property, $today, $to) as $a) {
            $checkin = $this->store->checkinOfReservation($property, $a['reservation_id']);
            $link = $this->store->liveLinks($property, $a['reservation_id'], $now)[0] ?? null;
            $rows[] = [
                'reservation_id' => $a['reservation_id'], 'number' => $a['number'], 'guest_name' => $a['guest_name'], 'arrival' => $a['arrival'], 'departure' => $a['departure'], 'room_type' => $a['room_type'], 'has_stay' => $a['has_stay'],
                'checkin' => $checkin === null ? null : ['id' => $checkin['id'], 'status' => $checkin['status']],
                'link' => $link === null ? null : ['id' => $link['id'], 'token' => $this->tokens->reveal((string) $link['token_cipher']), 'expires_at' => $this->iso($link['expires_at']), 'lock_version' => (int) $link['lock_version']],
            ];
        }

        $lobby = $this->store->liveLinks($property, null, $now)[0] ?? null;

        return [
            'arrivals' => $rows,
            'lobby' => $lobby === null ? null : ['id' => $lobby['id'], 'token' => $this->tokens->reveal((string) $lobby['token_cipher']), 'expires_at' => $this->iso($lobby['expires_at'])],
            'queue_count' => count($this->store->checkinsByStatus($property, 'submitted', 200)),
        ];
    }

    /**
     * A new link for a reservation; the one before it, if any, stops working.
     *
     * @return array{id: string, token: string, expires_at: string}
     */
    public function issue(PropertyId $property, string $actorId, string $reservationId): array
    {
        $this->access->require($property, $actorId, GuestAccess::CHECKIN_MANAGE, 'This person may not manage the self check-in.');
        $arrival = $this->desk->arrival($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
        $window = CheckInWindow::of($arrival, $this->businessDate->current($property)->toString(), (int) config('guest.checkin.open_days_before'));

        if ($window['state'] === CheckInWindow::UNAVAILABLE || $window['state'] === CheckInWindow::ENDED) {
            throw Refusal::stateConflict('This reservation can no longer be pre-registered.');
        }

        $checkin = $this->store->checkinOfReservation($property, $arrival['reservation_id']);

        if ($checkin !== null && $checkin['status'] !== 'rejected') {
            throw Refusal::stateConflict('This guest has sent the details already.');
        }

        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();
        $issued = $this->tokens->issue();
        $id = $this->ids->next();
        $expires = $this->expiry($now, (int) config('guest.checkin.link_hours') * 60, $arrival['departure']);

        $this->transactions->run(function () use ($property, $actor, $arrival, $issued, $id, $expires, $now): void {
            foreach ($this->store->liveLinks($property, $arrival['reservation_id'], $now) as $old) {
                $this->store->updateLink($property, (string) $old['id'], (int) $old['lock_version'], ['revoked_at' => $now], $now);
            }

            $this->store->addLink($property, ['id' => $id, 'kind' => 'reservation', 'reservation_id' => $arrival['reservation_id'], 'token_hash' => $issued['hash'], 'token_cipher' => $issued['cipher'], 'expires_at' => $expires, 'revoked_at' => null, 'created_by' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest.checkin_link.issued', 'guest_checkin_link', $id, null, ['reservation_id' => $arrival['reservation_id'], 'expires_at' => $expires->format('c')]));
        });

        return ['id' => $id, 'token' => $issued['token'], 'expires_at' => $this->iso($expires)];
    }

    public function revoke(PropertyId $property, string $actorId, string $linkId): void
    {
        $this->access->require($property, $actorId, GuestAccess::CHECKIN_MANAGE, 'This person may not manage the self check-in.');
        $link = $this->store->link($property, strtolower($linkId)) ?? throw Refusal::notFound('Link not found.');
        $now = $this->clock->nowUtc();

        if ($link['revoked_at'] !== null) {
            return;
        }

        $this->transactions->run(function () use ($property, $actorId, $link, $now): void {
            if (! $this->store->updateLink($property, (string) $link['id'], (int) $link['lock_version'], ['revoked_at' => $now], $now)) {
                throw Refusal::stateConflict('This link changed; look again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'guest.checkin_link.revoked', 'guest_checkin_link', (string) $link['id'], null, ['kind' => $link['kind']]));
        });
    }

    /**
     * The code at the lobby: the one in force, a new one when there is none or when asked, which ends the old one.
     *
     * @return array{id: string, token: string, expires_at: string}
     */
    public function lobby(PropertyId $property, string $actorId, bool $renew): array
    {
        $this->access->require($property, $actorId, GuestAccess::CHECKIN_MANAGE, 'This person may not manage the self check-in.');
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();
        $live = $this->store->liveLinks($property, null, $now);

        if ($live !== [] && ! $renew) {
            return ['id' => (string) $live[0]['id'], 'token' => $this->tokens->reveal((string) $live[0]['token_cipher']), 'expires_at' => $this->iso($live[0]['expires_at'])];
        }

        $issued = $this->tokens->issue();
        $id = $this->ids->next();
        $expires = $now->modify('+'.max(1, (int) config('guest.checkin.lobby_code_days')).' days');

        $this->transactions->run(function () use ($property, $actor, $live, $issued, $id, $expires, $now): void {
            foreach ($live as $old) {
                $this->store->updateLink($property, (string) $old['id'], (int) $old['lock_version'], ['revoked_at' => $now], $now);
            }

            $this->store->addLink($property, ['id' => $id, 'kind' => 'lobby', 'reservation_id' => null, 'token_hash' => $issued['hash'], 'token_cipher' => $issued['cipher'], 'expires_at' => $expires, 'revoked_at' => null, 'created_by' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest.checkin_lobby_code.issued', 'guest_checkin_link', $id, null, ['renewed' => $live !== []]));
        });

        return ['id' => $id, 'token' => $issued['token'], 'expires_at' => $this->iso($expires)];
    }

    /**
     * The guest at the lobby gives the reservation number and a name of the guest.
     *
     * @param  array<string, mixed>  $lobby  the lobby link, as `SelfCheckInService::resolve` answers
     * @return array{token: string}
     */
    public function lookup(array $lobby, string $number, string $name): array
    {
        /** @var PropertyId $property */
        $property = $lobby['property'];

        if ($lobby['kind'] !== 'lobby') {
            throw Refusal::notFound('This link does not work.');
        }

        $now = $this->clock->nowUtc();
        $subject = $this->tokens->hash($property->toString().'|'.mb_strtolower(trim($number)));
        $since = $now->modify('-'.max(1, (int) config('guest.checkin.lookup_lock_minutes')).' minutes');

        if ($this->store->attemptsSince($property, $subject, $since) >= max(1, (int) config('guest.checkin.lookup_max_attempts'))) {
            throw Refusal::stateConflict('Too many tries. Ask the front desk, or try again in a few minutes.');
        }

        $arrival = $this->desk->arrivalByNumber($property, $number);

        if ($arrival === null || ! in_array($arrival['status'], ['confirmed', 'guaranteed'], true) || ! GuestNameMatcher::matches($arrival['guest_name'], $name)) {
            $this->store->addAttempt($property, $subject, $now);
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest.checkin_lookup.failed', 'guest_checkin_link', (string) $lobby['id'], null, ['attempts' => $this->store->attemptsSince($property, $subject, $since)]));

            throw Refusal::invalid('The reservation number and the name do not match a booking. Check them or ask the front desk.', ['reservation_number']);
        }

        $issued = $this->tokens->issue();
        $id = $this->ids->next();
        $expires = $this->expiry($now, (int) config('guest.checkin.lobby_link_minutes'), $arrival['departure']);

        $this->transactions->run(function () use ($property, $arrival, $issued, $id, $expires, $now): void {
            $this->store->addLink($property, ['id' => $id, 'kind' => 'reservation', 'reservation_id' => $arrival['reservation_id'], 'token_hash' => $issued['hash'], 'token_cipher' => $issued['cipher'], 'expires_at' => $expires, 'revoked_at' => null, 'created_by' => null], $now);
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest.checkin_link.from_lobby', 'guest_checkin_link', $id, null, ['reservation_id' => $arrival['reservation_id']]));
        });

        return ['token' => $issued['token']];
    }

    /** Not before `$minutes` from now, and never past the end of the day after the departure. */
    private function expiry(DateTimeImmutable $now, int $minutes, string $departure): DateTimeImmutable
    {
        $wanted = $now->modify('+'.max(1, $minutes).' minutes');
        $cap = (new DateTimeImmutable($departure.' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day');

        return $wanted < $cap ? $wanted : ($cap > $now ? $cap : $now->modify('+1 minute'));
    }

    private function iso(mixed $value): string
    {
        $at = $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
