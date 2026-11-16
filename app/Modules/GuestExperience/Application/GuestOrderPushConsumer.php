<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Application\Notifications\DutyFilter;
use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;

/**
 * Tells the kitchen and the F&B service, on the devices of those who allowed notices, that a guest ordered from a room or table QR code: where it goes and the bill number, never the guest or the dishes.
 * Only when web push is set up; the order is on the kitchen board either way.
 */
final readonly class GuestOrderPushConsumer implements OutboxConsumer
{
    public const EVENT = 'guest.order.placed';

    /** Who makes and who brings the food. */
    public const PERMISSIONS = ['kitchen.board.operate', 'fnb.pos.operate'];

    public function __construct(private StaffDirectory $staff, private PushNotifier $push, private PropertyContext $context, private DutyFilter $duty) {}

    public function name(): string
    {
        return 'guest.order-push';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT;
    }

    public function consume(OutboxMessage $message): void
    {
        if (! $this->push->enabled()) {
            return;
        }

        $this->context->run($message->event->propertyId, function () use ($message): void {
            $property = $message->event->propertyId;
            $d = $message->event->data;
            $ids = [];

            foreach (self::PERMISSIONS as $permission) {
                foreach ($this->staff->withPermission($property, $permission) as $person) {
                    $ids[strtolower($person['id'])] = true;
                }
            }

            $ids = $this->duty->onDuty($property, array_keys($ids));

            if ($ids === []) {
                return;
            }

            $this->push->notify($property, $ids, 'Pesanan tamu baru / New guest order', self::place((string) ($d['label'] ?? '')).' · '.(string) ($d['bill_number'] ?? ''), '/kitchen', 'guest_order');
        });
    }

    /** "Room 101" and "Table M1 · Restaurant", as the codes were made, in both languages. */
    public static function place(string $label): string
    {
        if (preg_match('/^(Room|Table) (.+)$/', $label, $m) !== 1) {
            return $label === '' ? '-' : $label;
        }

        return ($m[1] === 'Room' ? 'Kamar ' : 'Meja ').$m[2].' / '.$label;
    }
}
