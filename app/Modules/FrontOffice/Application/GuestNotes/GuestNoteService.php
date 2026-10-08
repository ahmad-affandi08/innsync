<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestNotes;

use App\Modules\FrontOffice\Application\FrontDeskAccess;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * A flag (VIP, needs attention) and a short note that follow a guest from stay to stay: "prefers a high floor", "no peanuts in the food". It is for what the desk
 * needs to serve the guest, not for health, religion or other sensitive facts about a person, and the screen says so.
 */
final readonly class GuestNoteService
{
    public const FLAGS = ['vip', 'attention'];

    public function __construct(private GuestNoteStore $store, private FrontDeskAccess $access, private TransactionRunner $transactions, private AuditTrail $audit) {}

    /** @return array{flag: string|null, note: string|null, updated_at: string|null, may_write: bool} */
    public function forReservation(PropertyId $property, string $actorId, string $reservationId): array
    {
        $this->access->read($property, $actorId);
        $guest = $this->store->guestOf($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
        $found = $this->store->find($property, GuestKey::of($guest['name'], $guest['phone']));

        return ['flag' => $found['flag'] ?? null, 'note' => $found['note'] ?? null, 'updated_at' => $found['updated_at'] ?? null, 'may_write' => $this->access->mayWrite($property, $actorId)];
    }

    public function save(PropertyId $property, string $actorId, string $reservationId, ?string $flag, ?string $note): void
    {
        $this->access->write($property, $actorId);
        $guest = $this->store->guestOf($property, strtolower($reservationId)) ?? throw Refusal::notFound('Reservation not found.');
        $flag = $flag === null || $flag === '' ? null : $flag;
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($flag !== null && ! in_array($flag, self::FLAGS, true)) {
            throw Refusal::invalid('Choose VIP or needs attention.', ['flag']);
        }

        if ($note !== null && mb_strlen($note) > 500) {
            throw Refusal::invalid('The note is at most 500 characters.', ['note']);
        }

        $key = GuestKey::of($guest['name'], $guest['phone']);
        $before = $this->store->find($property, $key);

        $this->transactions->run(function () use ($property, $actorId, $key, $flag, $note, $before): void {
            $this->store->save($property, $key, $flag, $note, strtolower($actorId));
            // The audit holds that a note changed and the flag, never the text of the note.
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'guest.note.saved', 'guest_note', substr($key, 0, 26), $before === null ? null : ['flag' => $before['flag'], 'has_note' => $before['note'] !== null], ['flag' => $flag, 'has_note' => $note !== null]));
        });
    }
}
