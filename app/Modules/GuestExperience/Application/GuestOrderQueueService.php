<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FnbSales\Application\GuestOrdering;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What guests ordered from their phones, for the people who serve them (FR-GST-012, FR-GST-014). Every order is also a bill at the point of sale and a ticket in the kitchen; this list adds what only the guest
 * self-service knows: where the code was, who the guest said they were, and how they want to pay. A charge to the room waits here for a person to check it against the guest in front of them or at the desk; a person
 * who verifies it only makes it payable: the payment is taken at the cashier, as any bill is, and nothing reaches the folio from here. A refused charge is shown to the cashier and the guest as such.
 */
final readonly class GuestOrderQueueService
{
    public function __construct(private GuestOrderStore $orders, private GuestOrdering $ordering, private GuestAccess $access, private TransactionRunner $transactions, private AuditTrail $audit, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::ORDER_MANAGE, 'This person may not see the guests\' orders.');
        $rows = $this->orders->recent($property, $this->clock->nowUtc()->modify('-24 hours'), 200);

        return [
            'orders' => array_map(fn (array $o): array => [
                'id' => $o['id'], 'bill_id' => $o['bill_id'], 'bill_number' => $o['bill_number'], 'kind' => $o['kind'], 'label' => $o['point_label'], 'guest_name' => $o['guest_name'], 'room_number' => $o['room_number'],
                'placed_at' => str_replace(' ', 'T', substr((string) $o['created_at'], 0, 19)).'Z', 'payment' => $o['payment_preference'], 'room_charge' => $o['room_charge_state'], 'verify_note' => $o['verify_note'], 'subtotal_minor' => (int) $o['subtotal_minor'], 'note' => $o['note'],
                'bill_status' => $this->ordering->stateOf($property, $o['bill_id'])['status'] ?? 'unknown', 'lines' => count($o['line_ids']), 'lock_version' => (int) $o['lock_version'],
            ], $rows),
            'pending' => count(array_filter($rows, static fn (array $o): bool => $o['room_charge_state'] === 'pending')),
        ];
    }

    /** @return array<string, mixed> */
    public function decide(PropertyId $property, string $actorId, string $id, int $lock, bool $accept, ?string $note): array
    {
        $this->access->require($property, $actorId, GuestAccess::ORDER_MANAGE, 'This person may not verify a charge to a room.');
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ((! $accept && $note === null) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid($accept ? 'The note is at most 200 characters.' : 'Say why the charge is refused, in at most 200 characters.', ['note']);
        }

        $order = $this->orders->find($property, strtolower($id)) ?? throw Refusal::notFound('Order not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $order, $lock, $accept, $note): void {
            if ($order['room_charge_state'] !== 'pending') {
                throw Refusal::stateConflict('This charge was decided already, or none was asked for.');
            }

            $now = $this->clock->nowUtc();
            $state = $accept ? 'verified' : 'rejected';

            if (! $this->orders->update($property, $order['id'], $lock, ['room_charge_state' => $state, 'verified_by' => $actor, 'verified_at' => $now, 'verify_note' => $note], $now)) {
                throw Refusal::stateConflict('This order was changed by someone else. Reload it and check it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest_order.room_charge_'.$state, 'guest_order', $order['id'], ['room_charge' => 'pending'], ['room_charge' => $state, 'bill' => $order['bill_number']], $note));
        });

        return $this->overview($property, $actorId);
    }
}
