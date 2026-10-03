<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffContacts;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Escalation of a laundry order that is past its promised time (FR-LDY-011). Express orders go first in the queue and overdue ones are marked there; this is the notice: once, the people who work the
 * laundry (those who process it and those who may cancel an order, which is the supervisor) are told by e-mail which order, for which room, promised when and where it stands. The order is marked so the
 * notice is never repeated, whatever the number of runs; the dashboard shows the overdue orders for as long as they are overdue. The notice is sent after the mark is kept, so a failing mailer cannot
 * hold the order back and a retry cannot send it twice.
 */
final readonly class LaundryEscalationService
{
    public const EVENT = 'laundry.order.escalated';

    public function __construct(
        private LaundryEscalations $store,
        private StaffDirectory $staff,
        private StaffContacts $contacts,
        private EmailNotifier $notifier,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @return int the orders escalated in this run */
    public function run(PropertyId $property, int $max): int
    {
        $now = $this->clock->nowUtc();
        $orders = $this->store->overdue($property, $now, max(1, $max));

        if ($orders === []) {
            return 0;
        }

        $people = [];

        foreach ([LaundryService::PROCESS_PERMISSION, LaundryService::CANCEL_PERMISSION] as $permission) {
            foreach ($this->staff->withPermission($property, $permission) as $person) {
                $people[$person['id']] = true;
            }
        }

        $addresses = array_values($this->contacts->emailsOf($property, array_keys($people)));
        $escalated = 0;

        foreach ($orders as $order) {
            $kept = $this->transactions->run(function () use ($property, $order, $now): bool {
                if (! $this->store->markEscalated($property, $order['id'], $now)) {
                    return false;
                }

                $this->audit->record(new AuditEntry($property->toString(), null, 'laundry.order.escalated', 'laundry_order', $order['id'], null, ['number' => $order['number'], 'status' => $order['status'], 'express' => $order['express'], 'promised_at' => $order['promised_at']]));
                $this->outbox->publish(new OutboxEvent($property, self::EVENT, $order['id'], 1, ['order_id' => $order['id'], 'number' => $order['number'], 'room_number' => $order['room_number'], 'status' => $order['status'], 'express' => $order['express']]));

                return true;
            });

            if (! $kept) {
                continue;
            }

            $escalated++;
            $promised = (new DateTimeImmutable($order['promised_at'], new DateTimeZone('UTC')))->format('Y-m-d H:i').' UTC';
            $subject = sprintf('Laundry %s is past its promised time', $order['number']);
            $body = sprintf("Laundry order %s for room %s was promised by %s and is still with the laundry (%s).%s\n\nOpen the laundry queue to see it.", $order['number'], $order['room_number'], $promised, $order['status'], $order['express'] ? ' It is an express order.' : '');

            foreach ($addresses as $address) {
                $this->notifier->notify($address, $subject, $body);
            }
        }

        return $escalated;
    }
}
