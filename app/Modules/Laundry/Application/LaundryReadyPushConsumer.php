<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Notifications\DutyFilter;
use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;

/**
 * Tells the people who bring laundry back to the rooms, on the devices of those who allowed notices, that an order is ready: the room and the order number, never the guest. It goes to those who may deliver
 * (`laundry.order.deliver`) except the one who marked it ready, and only when web push is set up; the order is on the laundry list either way.
 */
final readonly class LaundryReadyPushConsumer implements OutboxConsumer
{
    public const EVENT = 'laundry.order.ready';

    public function __construct(private StaffDirectory $staff, private RoomCatalogReader $rooms, private PushNotifier $push, private PropertyContext $context, private DutyFilter $duty) {}

    public function name(): string
    {
        return 'laundry.ready-push';
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
            $ids = array_map(static fn (array $p): string => strtolower($p['id']), $this->staff->withPermission($property, LaundryService::DELIVER_PERMISSION));
            $ids = $this->duty->onDuty($property, array_values(array_diff($ids, [strtolower((string) ($d['actor_id'] ?? ''))])));

            if ($ids === []) {
                return;
            }

            $room = $this->rooms->room($property, (string) ($d['room_id'] ?? ''))?->number ?? '-';
            $number = (string) ($d['number'] ?? '');

            $this->push->notify($property, $ids, 'Laundry siap diantar / Laundry ready', "Kamar {$room} · {$number} / Room {$room} · {$number}", '/laundry/orders/'.strtolower((string) ($d['order_id'] ?? '')), 'laundry');
        });
    }
}
