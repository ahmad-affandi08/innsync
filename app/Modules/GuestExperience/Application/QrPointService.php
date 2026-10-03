<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FnbSales\Application\GuestOrdering;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The QR codes of the rooms and the tables (FR-GST-010, FR-GST-018). Each room and each table has one code that opens the menu; it carries a random token and nothing else (no room number, no table id,
 * no property), so one code tells nothing about another and a guessed address opens nothing. The owner makes the codes that are missing in one step, prints them, switches one off, or rotates one,
 * which ends every session held on it and makes the printed code stop working. Printing reads every token and is audited.
 */
final readonly class QrPointService
{
    public function __construct(
        private QrPointStore $points,
        private GuestSessionStore $sessions,
        private GuestTokens $tokens,
        private GuestOrdering $ordering,
        private RoomCatalogReader $rooms,
        private GuestAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::QR_MANAGE, 'This person may not manage the QR codes.');
        $points = $this->points->all($property);
        $have = [];

        foreach ($points as $p) {
            $have[$p['kind'].':'.$p['target_id']] = true;
        }

        $missing = 0;

        foreach ($this->rooms->activeRooms($property) as $r) {
            $missing += isset($have['room:'.$r->id]) ? 0 : 1;
        }

        foreach ($this->ordering->tables($property) as $t) {
            $missing += isset($have['table:'.$t['id']]) ? 0 : 1;
        }

        return [
            'points' => array_map(static fn (array $p): array => ['id' => $p['id'], 'kind' => $p['kind'], 'label' => $p['label'], 'is_active' => (bool) $p['is_active'], 'rotated_at' => $p['rotated_at'] === null ? null : str_replace(' ', 'T', substr((string) $p['rotated_at'], 0, 19)).'Z', 'lock_version' => (int) $p['lock_version']], $points),
            'missing' => $missing, 'room_outlets' => $this->ordering->roomOutlets($property),
        ];
    }

    /** Makes the codes the rooms and tables in use do not have yet. @return array<string, mixed> */
    public function provision(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::QR_MANAGE, 'This person may not manage the QR codes.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor): void {
            $made = ['room' => 0, 'table' => 0];
            $now = $this->clock->nowUtc();

            foreach ($this->rooms->activeRooms($property) as $r) {
                $made['room'] += $this->make($property, $actor, 'room', $r->id, 'Room '.$r->number, $now) ? 1 : 0;
            }

            foreach ($this->ordering->tables($property) as $t) {
                $made['table'] += $this->make($property, $actor, 'table', $t['id'], 'Table '.$t['code'].' · '.$t['name'], $now) ? 1 : 0;
            }

            if ($made['room'] + $made['table'] > 0) {
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest_qr.provisioned', 'guest_qr', $property->toString(), null, $made));
            }
        });

        return $this->overview($property, $actorId);
    }

    /** A new token for a code: the printed one stops working and the sessions on it end. @return array<string, mixed> */
    public function rotate(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, GuestAccess::QR_MANAGE, 'This person may not manage the QR codes.');
        $point = $this->points->find($property, strtolower($id)) ?? throw Refusal::notFound('Code not found.');
        $actor = strtolower($actorId);
        $issued = $this->tokens->issue();

        $this->transactions->run(function () use ($property, $actor, $point, $lock, $issued): void {
            $now = $this->clock->nowUtc();

            if (! $this->points->update($property, $point['id'], $lock, ['token_hash' => $issued['hash'], 'token_cipher' => $issued['cipher'], 'rotated_at' => $now], $now)) {
                throw Refusal::stateConflict('This code was changed by someone else. Reload it and check it.');
            }

            $this->sessions->endOfCode($property, $point['id'], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest_qr.rotated', 'guest_qr', $point['id'], ['label' => $point['label']], ['rotated' => true]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function setActive(PropertyId $property, string $actorId, string $id, int $lock, bool $active): array
    {
        $this->access->require($property, $actorId, GuestAccess::QR_MANAGE, 'This person may not manage the QR codes.');
        $point = $this->points->find($property, strtolower($id)) ?? throw Refusal::notFound('Code not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $point, $lock, $active): void {
            $now = $this->clock->nowUtc();

            if (! $this->points->update($property, $point['id'], $lock, ['is_active' => $active], $now)) {
                throw Refusal::stateConflict('This code was changed by someone else. Reload it and check it.');
            }

            if (! $active) {
                $this->sessions->endOfCode($property, $point['id'], $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'guest_qr.enabled' : 'guest_qr.disabled', 'guest_qr', $point['id'], ['is_active' => ! $active], ['is_active' => $active]));
        });

        return $this->overview($property, $actorId);
    }

    /**
     * The codes with their tokens, to be printed. Reading them is audited.
     *
     * @return list<array{id: string, kind: string, label: string, token: string}>
     */
    public function printable(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::QR_MANAGE, 'This person may not manage the QR codes.');
        $out = [];

        foreach ($this->points->all($property) as $p) {
            if ((bool) $p['is_active']) {
                $out[] = ['id' => $p['id'], 'kind' => $p['kind'], 'label' => $p['label'], 'token' => $this->tokens->reveal((string) $p['token_cipher'])];
            }
        }

        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'guest_qr.printed', 'guest_qr', $property->toString(), null, ['codes' => count($out)]));

        return $out;
    }

    private function make(PropertyId $property, string $actor, string $kind, string $targetId, string $label, \DateTimeImmutable $now): bool
    {
        if ($this->points->byTarget($property, $kind, $targetId) !== null) {
            return false;
        }

        $issued = $this->tokens->issue();

        return $this->points->add($property, ['id' => $this->ids->next(), 'kind' => $kind, 'target_id' => $targetId, 'label' => mb_substr($label, 0, 40), 'token_hash' => $issued['hash'], 'token_cipher' => $issued['cipher'], 'is_active' => true, 'created_by' => $actor, 'rotated_at' => null], $now);
    }
}
