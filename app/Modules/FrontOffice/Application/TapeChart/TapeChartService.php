<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\TapeChart;

use App\Modules\FrontOffice\Application\FrontDeskAccess;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The room calendar: every room against the nights of a window, with the reservations and the rooms taken out of sale laid on it, and the reservations that
 * have no room yet listed by room type. Read only. A column is a night: a guest who arrives on the 10th and leaves on the 12th holds the nights of the 10th and the 11th.
 * Only the guest's name is shown; contact details stay on the reservation.
 */
final readonly class TapeChartService
{
    public const DAYS = [7, 14, 30];

    public function __construct(private TapeChartReader $chart, private RoomCatalogReader $rooms, private BusinessDateProvider $businessDate, private PermissionChecker $permissions, private PropertyContext $property, private FrontDeskAccess $desk) {}

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, ?string $from, int $days): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, ReservationService::VIEW_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, ReservationService::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the room calendar.');
        }

        $days = in_array($days, self::DAYS, true) ? $days : 14;
        $today = $this->businessDate->current($property)->toString();
        $start = $this->date($from) ?? $today;
        $dates = [];

        for ($i = 0; $i < $days; $i++) {
            $dates[] = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify("+{$i} day")->format('Y-m-d');
        }

        $end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify("+{$days} day")->format('Y-m-d');
        $reservations = $this->chart->reservations($property, $start, $end);
        $blocks = $this->chart->blocks($property, $start, $end);
        $types = $this->rooms->activeTypes($property);
        $byRoom = [];
        $unassigned = [];

        foreach ($reservations as $r) {
            $bar = ['planned' => false, 'kind' => 'reservation', 'id' => $r['id'], 'number' => $r['number'], 'label' => $r['guest_name'], 'status' => $r['status'], 'start' => $r['arrival'], 'end' => $r['departure']];

            if ($r['room_id'] !== null) {
                $byRoom[$r['room_id']][] = $bar;
            } elseif ($r['planned_room_id'] !== null) {
                // Planned, not assigned: shown in the planned room, drawn differently, and still movable.
                $byRoom[$r['planned_room_id']][] = [...$bar, 'planned' => true];
            } else {
                $unassigned[$r['room_type_id']][] = $bar;
            }
        }

        foreach ($blocks as $b) {
            // A block runs to and including its end date, so it holds nights up to the day after.
            $byRoom[$b['room_id']][] = ['kind' => 'block', 'id' => null, 'number' => null, 'label' => $b['reason'], 'status' => $b['kind'], 'start' => $b['start'], 'end' => (new DateTimeImmutable($b['end'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d')];
        }

        $roomsOut = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            $roomsOut[] = ['id' => $room->id, 'number' => $room->number, 'type_id' => $room->roomTypeId, 'floor' => $room->floor, 'bars' => $byRoom[$room->id] ?? []];
        }

        usort($roomsOut, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return [
            'from' => $start,
            'days' => $days,
            'dates' => $dates,
            'today' => $today,
            'may_plan' => $this->desk->mayWrite($property, $actorId),
            'types' => array_map(static fn ($t): array => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name], $types),
            'rooms' => $roomsOut,
            'unassigned' => array_map(static fn (string $typeId, array $bars): array => ['type_id' => $typeId, 'bars' => $bars], array_keys($unassigned), array_values($unassigned)),
        ];
    }

    private function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
