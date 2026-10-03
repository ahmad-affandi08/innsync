<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FnbSales\Application\GuestOrdering;
use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What a guest does after scanning a code: sees the menu, orders, and follows the order (FR-GST-010..014, -019). The menu is the one of the outlet of the table, or of the room service outlet for a room, with what the kitchen
 * marked sold out shown as such and refused by the server. An order reaches the point of sale and the kitchen like a waiter's, marked as the guest's own. A room's order needs the stay proved first; the guest then says how
 * to pay: by QRIS at the cashier, charged to the room (a person verifies it before anything is posted to the folio), or later. The order is placed once whatever the number of taps (a key made by the page). A guest sees only the
 * orders of their own session, never a table's other orders or an earlier stay's.
 */
final readonly class GuestOrderService
{
    public const PAYMENTS = ['qris', 'room', 'later'];

    public function __construct(
        private GuestOrdering $ordering,
        private GuestOrderStore $orders,
        private GuestCharging $guests,
        private PropertyProfileReader $profile,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $session  as `GuestSessionService::resolve` answers
     * @return array<string, mixed>
     */
    public function menu(array $session): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $target = $this->target($property, $session);

        return [
            'hotel' => $this->profile->nameOf($property) ?? '', 'currency' => $this->currencies->currencyOf($property), 'kind' => $session['kind'], 'label' => $session['label'], 'verified' => $session['verified'], 'locked' => $session['locked'], 'guest_name' => $session['guest_name'] === null ? null : explode(' ', trim((string) $session['guest_name']))[0],
            'can_order' => $target !== null && ($session['kind'] === 'table' || $session['verified']), 'needs_proof' => $session['kind'] === 'room' && ! $session['verified'],
            'available' => $target !== null, 'menu' => $target === null ? [] : $this->ordering->menu($property, $target['outlet_id'], $session['kind'] === 'room' ? 'room_service' : 'dine_in'), 'payments' => self::PAYMENTS,
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  list<array{item_id: string, variant_id: string|null, modifier_ids: list<string>, quantity: int, note: string|null}>  $lines
     * @return array<string, mixed> the order
     */
    public function place(array $session, string $clientKey, array $lines, ?string $note, string $payment): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];

        if (preg_match('/^[A-Za-z0-9_-]{16,40}$/D', $clientKey) !== 1) {
            throw Refusal::invalid('The order has no key; reload the page and try again.', ['client_key']);
        }

        $existing = $this->orders->byClientKey($property, $session['id'], $clientKey);

        if ($existing !== null) {
            return $this->shape($property, $existing);
        }

        if (! in_array($payment, self::PAYMENTS, true)) {
            throw Refusal::invalid('Choose how to pay.', ['payment']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $max = (int) config('guest.order_max_lines');
        $maxQuantity = (int) config('guest.order_max_quantity');

        if ($lines === [] || count($lines) > $max) {
            throw Refusal::invalid('Choose between 1 and '.$max.' dishes.', ['lines']);
        }

        foreach ($lines as $l) {
            if ($l['quantity'] < 1 || $l['quantity'] > $maxQuantity || ($l['note'] !== null && mb_strlen($l['note']) > 120)) {
                throw Refusal::invalid('Each dish is ordered from 1 to '.$maxQuantity.' portions, with a note of at most 120 characters.', ['lines']);
            }
        }

        $target = $this->target($property, $session) ?? throw Refusal::stateConflict('This place cannot take orders now. Ask the staff.');

        if ($session['kind'] === 'room' && ! $session['verified']) {
            throw Refusal::forbidden('Confirm your room number and name before you order.');
        }

        if ($payment === 'room' && ! $session['verified']) {
            throw Refusal::forbidden('A charge to the room needs your room number and name confirmed first.');
        }

        if ($session['verified']) {
            $this->assertStillInHouse($property, $session);
        }

        if ($this->orders->countSince($property, $session['id'], $this->clock->nowUtc()->modify('-1 hour')) >= (int) config('guest.orders_per_hour')) {
            throw Refusal::stateConflict('Too many orders in the last hour. Ask the staff to take the next one.');
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $session, $clientKey, $lines, $note, $payment, $target, $id): void {
            $placed = $this->ordering->place($property, $target, $lines, $note);
            $now = $this->clock->nowUtc();
            $this->orders->add($property, [
                'id' => $id, 'session_id' => $session['id'], 'qr_point_id' => $session['point_id'], 'client_key' => $clientKey, 'kind' => $session['kind'], 'outlet_id' => $target['outlet_id'], 'bill_id' => $placed['bill_id'],
                'bill_number' => $placed['bill_number'], 'line_ids' => $placed['line_ids'], 'subtotal_minor' => $placed['subtotal_minor'], 'payment_preference' => $payment, 'room_charge_state' => $payment === 'room' ? 'pending' : 'none',
                'verified_by' => null, 'verified_at' => null, 'verify_note' => null, 'note' => $note,
            ], $now);
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest_order.placed', 'guest_order', $id, null, ['code' => $session['label'], 'bill' => $placed['bill_number'], 'lines' => count($placed['line_ids']), 'payment' => $payment, 'subtotal_minor' => $placed['subtotal_minor']]));
            $this->outbox->publish(new OutboxEvent($property, 'guest.order.placed', $id, 1, ['order_id' => $id, 'bill_id' => $placed['bill_id'], 'bill_number' => $placed['bill_number'], 'kind' => $session['kind'], 'payment' => $payment]));
        });

        return $this->shape($property, $this->orders->find($property, $id) ?? throw Refusal::notFound('Order not found.'));
    }

    /**
     * The orders of this session, with how far each dish is. Nothing of any other session.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function orders(array $session): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];

        return [
            'hotel' => $this->profile->nameOf($property) ?? '', 'kind' => $session['kind'], 'label' => $session['label'],
            'orders' => array_map(fn (array $o): array => $this->shape($property, $o), $this->orders->ofSession($property, $session['id'], 20)),
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function shape(PropertyId $property, array $o): array
    {
        $lines = $this->ordering->progress($property, $o['bill_id'], $o['line_ids']);
        $state = $this->ordering->stateOf($property, $o['bill_id']);
        $totals = $this->ordering->totalsOf($property, $o['bill_id']);
        $open = $lines !== [] && array_filter($lines, static fn (array $l): bool => $l['status'] === 'sent' && $l['prep_status'] !== 'served') !== [];

        return [
            'id' => $o['id'], 'bill_number' => $o['bill_number'], 'placed_at' => str_replace(' ', 'T', substr((string) $o['created_at'], 0, 19)).'Z', 'payment' => $o['payment_preference'], 'room_charge' => $o['room_charge_state'], 'note' => $o['note'],
            'bill_status' => $state['status'] ?? 'unknown', 'delivery' => $state['room_service'] ?? null, 'subtotal_minor' => (int) $o['subtotal_minor'], 'total_minor' => $totals['total_minor'] ?? null, 'currency' => $totals['currency'] ?? null, 'in_progress' => $open,
            'lines' => array_map(static fn (array $l): array => ['name' => $l['name'], 'variant' => $l['variant'], 'quantity' => $l['quantity'], 'status' => $l['status'] === 'sent' ? $l['prep_status'] : $l['status'], 'total_minor' => $l['total_minor']], $lines),
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{kind: string, outlet_id: string, table_id: string|null, room_id: string|null}|null where an order of this code goes, when it can go anywhere
     */
    private function target(PropertyId $property, array $session): ?array
    {
        if ($session['kind'] === 'table') {
            $table = $this->ordering->table($property, $session['target_id']);

            return $table === null ? null : ['kind' => 'table', 'outlet_id' => $table['outlet_id'], 'table_id' => $table['table_id'], 'room_id' => null];
        }

        $outlets = $this->ordering->roomOutlets($property);

        return $outlets === [] ? null : ['kind' => 'room', 'outlet_id' => $outlets[0]['id'], 'table_id' => null, 'room_id' => $session['target_id']];
    }

    /** @param array<string, mixed> $session */
    private function assertStillInHouse(PropertyId $property, array $session): void
    {
        if ($session['kind'] !== 'room') {
            return;
        }

        $stay = $this->guests->inHouseStayOfRoom($property, $session['target_id']);

        if ($stay === null || $stay['stay_id'] !== $session['stay_id']) {
            throw Refusal::forbidden('This room has no longer the guest who confirmed it. Ask the front desk.');
        }
    }
}
