<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Requests;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;

/**
 * Tells the front desk, on the devices of those who allowed notices, that a guest asked for something: the room, the kind of request and whether it is urgent, never the guest or what was written. It goes to people who
 * may work on guest requests and only when web push is set up; the request is on the screen either way.
 */
final readonly class GuestRequestPushConsumer implements OutboxConsumer
{
    public function __construct(private StaffDirectory $staff, private RoomCatalogReader $rooms, private PushNotifier $push, private PropertyContext $context) {}

    public function name(): string
    {
        return 'frontoffice.guest-request-push';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === 'frontoffice.guest_request.opened';
    }

    public function consume(OutboxMessage $message): void
    {
        if (! $this->push->enabled()) {
            return;
        }

        $this->context->run($message->event->propertyId, function () use ($message): void {
            $property = $message->event->propertyId;
            $d = $message->event->data;
            $ids = array_map(static fn (array $p): string => strtolower($p['id']), $this->staff->withPermission($property, GuestRequestService::MANAGE_PERMISSION));
            $ids = array_values(array_diff($ids, [strtolower((string) ($d['actor_id'] ?? ''))]));

            if ($ids === []) {
                return;
            }

            $room = $this->rooms->room($property, (string) ($d['room_id'] ?? ''))?->number ?? '-';
            $urgent = ($d['priority'] ?? 'normal') === 'urgent';
            $what = sprintf('%s · %s', $room, (string) ($d['category'] ?? ''));

            $this->push->notify($property, $ids, $urgent ? 'Permintaan tamu mendesak / Urgent guest request' : 'Permintaan tamu / Guest request', "Kamar {$what} · {$d['number']} / Room {$what} · {$d['number']}", '/front-office/requests', 'guest_request');
        });
    }
}
