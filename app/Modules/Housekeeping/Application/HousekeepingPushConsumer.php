<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;

/**
 * Tells housekeeping, on the devices of those who allowed notices, what needs them: a room task given to an attendant (to that attendant), a room left by its guest (to those who hand out tasks), and a
 * cleaned room waiting for inspection (to those who inspect). Only the room number, never the guest. The person who caused it is not told, and nothing is sent unless web push is set up; the board shows it
 * either way.
 */
final readonly class HousekeepingPushConsumer implements OutboxConsumer
{
    public const ASSIGNED = 'housekeeping.task.assigned';

    public const STATUS_CHANGED = 'housekeeping.room.status_changed';

    public function __construct(private StaffDirectory $staff, private RoomCatalogReader $rooms, private PushNotifier $push, private PropertyContext $context) {}

    public function name(): string
    {
        return 'housekeeping.push';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::ASSIGNED || $eventType === self::STATUS_CHANGED;
    }

    public function consume(OutboxMessage $message): void
    {
        if (! $this->push->enabled()) {
            return;
        }

        $this->context->run($message->event->propertyId, function () use ($message): void {
            $property = $message->event->propertyId;
            $d = $message->event->data;
            $actor = strtolower((string) ($d['actor_id'] ?? ''));
            $room = $this->rooms->room($property, (string) ($d['room_id'] ?? ''))?->number ?? '-';

            [$ids, $title, $body] = match (true) {
                $message->event->eventType === self::ASSIGNED => [[strtolower((string) ($d['assigned_to'] ?? ''))], 'Tugas kamar baru / New room task', "Kamar {$room} / Room {$room}"],
                ($d['event'] ?? null) === 'housekeeping.room.vacated' => [$this->holders(HousekeepingService::MANAGE_PERMISSION), 'Kamar kosong, perlu dibersihkan / Room vacated, to be cleaned', "Kamar {$room} / Room {$room}"],
                ($d['event'] ?? null) === 'housekeeping.task.finished' && ($d['to'] ?? null) === 'clean' => [$this->holders(HousekeepingService::INSPECT_PERMISSION), 'Kamar siap diinspeksi / Room ready for inspection', "Kamar {$room} / Room {$room}"],
                default => [[], '', ''],
            };
            $ids = array_values(array_diff(array_filter($ids), [$actor]));

            if ($ids !== []) {
                $this->push->notify($property, $ids, $title, $body, $message->event->eventType === self::ASSIGNED ? '/housekeeping/my-rooms' : '/housekeeping', 'housekeeping');
            }
        });
    }

    /** @return list<string> */
    private function holders(string $permission): array
    {
        return array_map(static fn (array $p): string => strtolower($p['id']), $this->staff->withPermission($this->context->current(), $permission));
    }
}
