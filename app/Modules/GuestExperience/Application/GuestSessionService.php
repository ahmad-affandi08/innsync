<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\GuestExperience\Domain\GuestNameMatcher;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The session a guest holds after scanning a code (FR-GST-018). Scanning opens a session on that one code, which ends by itself (a table's after a meal, a room's after a day) or when the code is rotated or
 * switched off. A session may browse the menu; to order for a room, or to charge an order to a room, the guest proves the stay: the room number and a name of the guest in it, checked against the front office.
 * The proof is limited to a few tries and then locked for a while, and a wrong answer never says which half was wrong, so neither the guests of the house nor their rooms can be found out by trying.
 */
final readonly class GuestSessionService
{
    public function __construct(
        private QrPointStore $points,
        private GuestSessionStore $sessions,
        private GuestTokens $tokens,
        private GuestCharging $guests,
        private RoomCatalogReader $rooms,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * A code was scanned. @return array{token: string, kind: string} the token of the new session, to be kept in the guest's browser
     *
     * @throws Refusal when the code is unknown or switched off; the answer is the same for both
     */
    public function enter(string $codeToken): array
    {
        $point = $this->tokens->wellFormed($codeToken) ? $this->points->byTokenHash($this->tokens->hash($codeToken)) : null;

        if ($point === null || ! (bool) $point['is_active']) {
            throw Refusal::notFound('This code does not work.');
        }

        $property = PropertyId::fromString((string) $point['property_id']);
        $now = $this->clock->nowUtc();
        $issued = $this->tokens->issue();
        $minutes = (int) (config('guest.session_minutes')[$point['kind']] ?? 240);
        $this->sessions->add($property, [
            'id' => $this->ids->next(), 'qr_point_id' => $point['id'], 'token_hash' => $issued['hash'], 'expires_at' => $now->modify("+{$minutes} minutes"), 'stay_id' => null, 'reservation_id' => null, 'room_id' => null, 'room_number' => null, 'guest_name' => null,
            'verified_at' => null, 'failed_attempts' => 0, 'locked_until' => null, 'created_at' => $now, 'last_seen_at' => $now,
        ]);

        return ['token' => $issued['token'], 'kind' => (string) $point['kind']];
    }

    /**
     * The session a token holds, if it is still good.
     *
     * @return array{id: string, property: PropertyId, point_id: string, kind: string, target_id: string, label: string, verified: bool, stay_id: string|null, reservation_id: string|null, room_id: string|null, guest_name: string|null, expires_at: string, locked: bool}|null
     */
    public function resolve(string $sessionToken): ?array
    {
        if (! $this->tokens->wellFormed($sessionToken)) {
            return null;
        }

        $s = $this->sessions->byTokenHash($this->tokens->hash($sessionToken));
        $now = $this->clock->nowUtc();

        if ($s === null || ! (bool) $s['point_active'] || $this->at($s['expires_at']) <= $now) {
            return null;
        }

        $property = PropertyId::fromString((string) $s['property_id']);

        if ($this->at($s['last_seen_at']) <= $now->modify('-1 minute')) {
            $this->sessions->update($property, $s['id'], ['last_seen_at' => $now]);
        }

        return [
            'id' => $s['id'], 'property' => $property, 'kind' => (string) $s['point_kind'], 'target_id' => (string) $s['point_target'], 'label' => (string) $s['point_label'], 'verified' => $s['verified_at'] !== null,
            'point_id' => (string) $s['qr_point_id'], 'stay_id' => $s['stay_id'], 'reservation_id' => $s['reservation_id'], 'room_id' => $s['room_id'], 'guest_name' => $s['guest_name'], 'expires_at' => $this->at($s['expires_at'])->format('Y-m-d\TH:i:s\Z'),
            'locked' => $s['locked_until'] !== null && $this->at($s['locked_until']) > $now,
        ];
    }

    /**
     * The guest proves the stay with the room number and a name of the guest in it.
     *
     * @param  array<string, mixed>  $session  as `resolve` answers
     *
     * @throws Refusal 422 with the same words whichever half was wrong; 429-like conflict while locked
     */
    public function verify(array $session, string $roomNumber, string $surname): void
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $row = $this->sessions->find($property, $session['id']) ?? throw Refusal::notFound('This session has ended.');
        $now = $this->clock->nowUtc();

        if ($row['locked_until'] !== null && $this->at($row['locked_until']) > $now) {
            throw Refusal::stateConflict('Too many tries. Ask the front desk, or try again in a few minutes.');
        }

        $typed = GuestNameMatcher::room($roomNumber);
        $stay = null;
        $roomId = null;
        $roomLabel = null;

        foreach ($this->rooms->activeRooms($property) as $r) {
            if (GuestNameMatcher::room($r->number) === $typed && ($session['kind'] !== 'room' || $r->id === $session['target_id'])) {
                $roomId = $r->id;
                $roomLabel = $r->number;
            }
        }

        if ($roomId !== null) {
            $found = $this->guests->inHouseStayOfRoom($property, $roomId);
            $stay = $found !== null && GuestNameMatcher::matches((string) ($found['guest_name'] ?? ''), $surname) ? $found : null;
        }

        if ($stay === null) {
            $attempts = (int) $row['failed_attempts'] + 1;
            $max = (int) config('guest.verify_max_attempts');
            $this->sessions->update($property, $row['id'], ['failed_attempts' => $attempts, 'locked_until' => $attempts >= $max ? $now->modify('+'.(int) config('guest.verify_lock_minutes').' minutes') : null]);
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest_session.verify_failed', 'guest_session', $row['id'], null, ['attempts' => $attempts, 'code' => $session['label']]));

            throw Refusal::invalid('The room number and the name do not match a guest in the house. Check them or ask the front desk.', ['room_number']);
        }

        $this->sessions->update($property, $row['id'], ['stay_id' => $stay['stay_id'], 'reservation_id' => $stay['reservation_id'], 'room_id' => $roomId, 'room_number' => $roomLabel, 'guest_name' => mb_substr((string) $stay['guest_name'], 0, 150), 'verified_at' => $now, 'failed_attempts' => 0, 'locked_until' => null]);
        $this->audit->record(new AuditEntry($property->toString(), null, 'guest_session.verified', 'guest_session', $row['id'], null, ['stay_id' => $stay['stay_id'], 'code' => $session['label']]));
    }

    private function at(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
