<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Feedback;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
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
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Comments and complaints from guests (FR-FO-031): what was said, how serious it is, who follows it up, what was done and the
 * proof. A complaint has a severity; a high or critical one needs a follow-up date, an owner before work starts, and a proof
 * reference to be resolved. The history of each item is an append-only list of events; what the guest said never changes and
 * nothing is deleted.
 *
 * Life: open, in progress, resolved, closed. A resolved item can be closed, or reopened with a note.
 */
final readonly class FeedbackService
{
    public const MANAGE_PERMISSION = 'front-office.feedback.manage';

    public const VIEW_PERMISSION = 'front-office.feedback.view';

    public const KINDS = ['complaint', 'compliment', 'suggestion'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const CHANNELS = ['in_person', 'phone', 'email', 'online', 'other'];

    public function __construct(
        private FeedbackRepository $feedback,
        private ReservationRepository $reservations,
        private StayRepository $stays,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
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
    public function record(
        PropertyId $property, string $actorId, string $kind, ?string $severity, string $channel, ?string $reservationId, ?string $guestName,
        string $summary, ?string $detail, ?string $followUpBy, ?string $clientKey = null,
    ): array {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $actor = strtolower($actorId);
        $summary = trim($summary);
        $detail = $detail === null || trim($detail) === '' ? null : trim($detail);
        $guestName = $guestName === null || trim($guestName) === '' ? null : trim($guestName);

        if ($summary === '' || mb_strlen($summary) > 150 || ($detail !== null && mb_strlen($detail) > 1000) || ($guestName !== null && mb_strlen($guestName) > 150)) {
            throw Refusal::invalid('Give a summary of at most 150 characters, details of at most 1000 and a guest name of at most 150.', ['summary', 'detail', 'guest_name']);
        }

        if (! in_array($kind, self::KINDS, true) || ! in_array($channel, self::CHANNELS, true)) {
            throw Refusal::invalid('Choose a kind and a channel from the lists.', ['kind', 'channel']);
        }

        if ($kind === 'complaint') {
            if (! in_array($severity, self::SEVERITIES, true)) {
                throw Refusal::invalid('Choose how serious the complaint is.', ['severity']);
            }
        } else {
            $severity = null;
        }

        $today = $this->businessDate->current($property);

        try {
            $due = $followUpBy === null || $followUpBy === '' ? null : BusinessDate::fromString($followUpBy);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['follow_up_by']);
        }

        if ($due !== null && $due->isBefore($today)) {
            throw Refusal::invalid('The follow-up date cannot be in the past.', ['follow_up_by']);
        }

        if ($due === null && in_array($severity, ['high', 'critical'], true)) {
            throw Refusal::invalid('A high or critical complaint needs a follow-up date.', ['follow_up_by']);
        }

        $id = $this->ids->next();
        $existing = null;

        $this->transactions->run(function () use ($property, $actor, $kind, $severity, $channel, $reservationId, $guestName, $summary, $detail, $due, $clientKey, $id, &$existing): void {
            if ($clientKey !== null && ($existing = $this->feedback->findByKey($property, $clientKey)) !== null) {
                return;
            }

            $reservation = null;
            $stay = null;

            if ($reservationId !== null && $reservationId !== '') {
                $reservation = $this->reservations->find($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
                $stay = $this->stays->findByReservation($property, $reservation->id);
            }

            $number = $this->numbers->next($property, 'FDB');
            $now = $this->clock->nowUtc();
            $this->feedback->add($property, [
                'id' => $id, 'number' => $number, 'client_key' => $clientKey, 'kind' => $kind, 'severity' => $severity, 'channel' => $channel, 'reservation_id' => $reservation?->id, 'stay_id' => $stay?->id,
                'room_id' => $stay?->roomId ?? $reservation?->roomId, 'guest_name' => $guestName ?? $reservation?->guestName, 'summary' => $summary, 'detail' => $detail, 'status' => 'open', 'owner_id' => null,
                'follow_up_by' => $due?->toString(), 'created_by' => $actor,
            ], $now);
            $this->feedback->addEvent($property, $this->ids->next(), $id, 'opened', null, $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'feedback.recorded', 'guest_feedback', $id, null, ['number' => $number, 'kind' => $kind, 'severity' => $severity, 'channel' => $channel, 'reservation_id' => $reservation?->id]));
            $this->announce($property, 'frontoffice.feedback.recorded', $id, ['number' => $number, 'kind' => $kind, 'severity' => $severity], $actor);

            if ($severity === 'critical') {
                $this->announce($property, 'frontoffice.feedback.critical', $id, ['number' => $number], $actor);
            }
        });

        return $this->describe($property, $existing ?? $this->feedback->find($property, $id) ?? throw Refusal::notFound('Item not found.'));
    }

    /** @return array<string, mixed> */
    public function assign(PropertyId $property, string $actorId, string $id, string $ownerId, int $lock): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $owner = strtolower($ownerId);

        if (! in_array($owner, array_column($this->staff->withPermission($property, self::MANAGE_PERMISSION), 'id'), true)) {
            throw Refusal::invalid('Choose someone who handles guest feedback.', ['owner_id']);
        }

        $names = $this->staff->namesOf($property, [$owner]);

        return $this->change($property, $actorId, $id, $lock, ['owner_id' => $owner], 'assigned', $names[$owner] ?? null, ['open', 'in_progress', 'resolved'], 'feedback.assigned');
    }

    /** @return array<string, mixed> */
    public function note(PropertyId $property, string $actorId, string $id, string $text): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 500) {
            throw Refusal::invalid('Write a note of at most 500 characters.', ['text']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $text): void {
            $item = $this->feedback->find($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');

            if ($item['status'] === 'closed') {
                throw Refusal::stateConflict('A closed item takes no more notes.');
            }

            $this->feedback->addEvent($property, $this->ids->next(), $item['id'], 'note', $text, $actor, $this->clock->nowUtc());
        });

        return $this->describe($property, $this->feedback->find($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.'));
    }

    /** @return array<string, mixed> */
    public function start(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $item = $this->require($property, $actorId, $id);

        if ($item['kind'] === 'complaint' && in_array($item['severity'], ['high', 'critical'], true) && $item['owner_id'] === null) {
            throw Refusal::stateConflict('Give a high or critical complaint an owner before work starts.');
        }

        return $this->change($property, $actorId, $id, $lock, ['status' => 'in_progress'], 'status', 'in_progress', ['open'], 'feedback.started');
    }

    /** @return array<string, mixed> */
    public function resolve(PropertyId $property, string $actorId, string $id, string $resolution, ?string $evidence, int $lock): array
    {
        $resolution = trim($resolution);
        $evidence = $evidence === null || trim($evidence) === '' ? null : trim($evidence);

        if ($resolution === '' || mb_strlen($resolution) > 500 || ($evidence !== null && mb_strlen($evidence) > 120)) {
            throw Refusal::invalid('Say what was done in at most 500 characters; the proof reference is at most 120.', ['resolution', 'evidence_ref']);
        }

        $item = $this->require($property, $actorId, $id);

        if ($item['kind'] === 'complaint' && in_array($item['severity'], ['high', 'critical'], true) && $evidence === null) {
            throw Refusal::invalid('A high or critical complaint needs a proof reference: a voucher, a message, a signed note.', ['evidence_ref']);
        }

        return $this->change($property, $actorId, $id, $lock, ['status' => 'resolved', 'resolution' => $resolution, 'evidence_ref' => $evidence, 'resolved_at' => $this->clock->nowUtc()], 'resolved', $resolution, ['open', 'in_progress'], 'feedback.resolved');
    }

    /** @return array<string, mixed> */
    public function close(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->change($property, $actorId, $id, $lock, ['status' => 'closed', 'closed_at' => $this->clock->nowUtc()], 'closed', null, ['resolved'], 'feedback.closed');
    }

    /** @return array<string, mixed> */
    public function reopen(PropertyId $property, string $actorId, string $id, string $note, int $lock): array
    {
        $note = trim($note);

        if ($note === '' || mb_strlen($note) > 300) {
            throw Refusal::invalid('Say why it is reopened, in at most 300 characters.', ['note']);
        }

        $item = $this->require($property, $actorId, $id);

        return $this->change($property, $actorId, $id, $lock, ['status' => 'in_progress', 'resolution' => null, 'evidence_ref' => null, 'resolved_at' => null], 'reopened', $note.($item['resolution'] === null ? '' : ' (was: '.$item['resolution'].')'), ['resolved'], 'feedback.reopened');
    }

    /**
     * @return array{items: list<array<string, mixed>>, may_manage: bool, kinds: list<string>, severities: list<string>, channels: list<string>}
     */
    public function queue(PropertyId $property, string $actorId, ?string $kind, ?string $status, ?string $severity, ?string $ownerId): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);

        if (($kind !== null && $kind !== '' && ! in_array($kind, self::KINDS, true)) || ($severity !== null && $severity !== '' && ! in_array($severity, self::SEVERITIES, true))
            || ($status !== null && $status !== '' && ! in_array($status, ['open', 'in_progress', 'resolved', 'closed', 'active'], true))) {
            throw Refusal::invalid('Choose a known kind, status and severity.', ['kind', 'status', 'severity']);
        }

        $filters = ['kind' => $kind, 'severity' => $severity, 'owner_id' => $ownerId];

        if ($status === 'active') {
            $filters['open_only'] = true;
        } else {
            $filters['status'] = $status;
        }

        $names = [];
        $items = $this->feedback->search($property, $filters, 200);
        $owners = array_values(array_unique(array_filter(array_column($items, 'owner_id'))));

        if ($owners !== []) {
            $names = $this->staff->namesOf($property, $owners);
        }

        $today = $this->businessDate->current($property)->toString();

        return [
            'items' => array_map(static fn (array $i): array => [
                ...$i, 'owner_name' => $i['owner_id'] === null ? null : ($names[$i['owner_id']] ?? null),
                'overdue' => in_array($i['status'], ['open', 'in_progress'], true) && $i['follow_up_by'] !== null && $i['follow_up_by'] < $today,
            ], $items),
            'may_manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property),
            'kinds' => self::KINDS, 'severities' => self::SEVERITIES, 'channels' => self::CHANNELS,
        ];
    }

    /** @return array<string, mixed> */
    public function view(PropertyId $property, string $actorId, string $id): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $item = $this->feedback->find($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');

        return [
            'item' => $this->describe($property, $item),
            'events' => $this->eventsOf($property, $item['id']),
            'may_manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property),
            'owners' => $this->staff->withPermission($property, self::MANAGE_PERMISSION),
        ];
    }

    // ---- internals ----

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $from
     * @return array<string, mixed>
     */
    private function change(PropertyId $property, string $actorId, string $id, int $lock, array $changes, string $eventKind, ?string $eventText, array $from, string $action): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $lock, $changes, $eventKind, $eventText, $from, $action): void {
            $before = $this->feedback->find($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');

            if (! in_array($before['status'], $from, true)) {
                throw Refusal::stateConflict('This item is '.str_replace('_', ' ', $before['status']).' and cannot be moved there.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->feedback->change($property, $before['id'], $lock, $changes, $now)) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            $this->feedback->addEvent($property, $this->ids->next(), $before['id'], $eventKind, $eventText === null ? null : mb_substr($eventText, 0, 500), $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, $action, 'guest_feedback', $before['id'], ['status' => $before['status'], 'owner_id' => $before['owner_id']], [...array_diff_key($changes, ['resolved_at' => 1, 'closed_at' => 1]), 'number' => $before['number']], $eventText));

            if (in_array($eventKind, ['resolved', 'closed'], true)) {
                $this->announce($property, 'frontoffice.feedback.'.$eventKind, $before['id'], ['number' => $before['number'], 'kind' => $before['kind'], 'severity' => $before['severity']], $actor);
            }
        });

        return $this->describe($property, $this->feedback->find($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.'));
    }

    /** @return array<string, mixed> */
    private function require(PropertyId $property, string $actorId, string $id): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);

        return $this->feedback->find($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function describe(PropertyId $property, array $item): array
    {
        $today = $this->businessDate->current($property)->toString();
        $names = array_values(array_filter([$item['owner_id']]));

        return [
            ...$item,
            'owner_name' => $names === [] ? null : ($this->staff->namesOf($property, $names)[$item['owner_id']] ?? null),
            'overdue' => in_array($item['status'], ['open', 'in_progress'], true) && $item['follow_up_by'] !== null && $item['follow_up_by'] < $today,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function eventsOf(PropertyId $property, string $feedbackId): array
    {
        $events = $this->feedback->events($property, $feedbackId);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($events, 'actor_id'))));

        return array_map(static fn (array $e): array => [...$e, 'actor_name' => $names[$e['actor_id']] ?? null], $events);
    }

    /** @param array<string, mixed> $data */
    private function announce(PropertyId $property, string $type, string $id, array $data, string $actor): void
    {
        $this->outbox->publish(new OutboxEvent($property, $type, $id, 1, [...$data, 'feedback_id' => $id, 'actor_id' => $actor]));
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use guest feedback.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
