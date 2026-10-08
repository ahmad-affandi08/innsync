<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Desk;

use App\Modules\FrontOffice\Application\Board\RoomBoardService;
use App\Modules\FrontOffice\Application\Reminders\ReminderService;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The receptionist's day on one screen: who is due to arrive, who is due to leave, how the rooms stand, what the desk must remember and which requests are open. It adds nothing of its own;
 * it gathers what the room board, the reminders and the guest requests already know, each under its own permission.
 */
final readonly class DeskTodayService
{
    public function __construct(private RoomBoardService $board, private GuestRequestService $requests, private ReminderService $reminders) {}

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId): array
    {
        $board = $this->board->board($property, $actorId);
        $today = $board['business_date'];
        $departures = [];

        foreach ($board['rooms'] as $room) {
            if ($room['stay_id'] !== null && $room['expected_departure'] !== null && $room['expected_departure'] <= $today) {
                $departures[] = ['room' => $room['number'], 'type' => $room['type'], 'stay_id' => $room['stay_id'], 'expected_departure' => $room['expected_departure'], 'open_requests' => $room['open_requests']];
            }
        }

        $arrivals = array_values(array_filter($board['arrivals'], static fn (array $a): bool => $a['arrival'] <= $today));

        return [
            'business_date' => $today,
            'counts' => $board['counts'] + ['rooms' => count($board['rooms'])],
            'arrivals' => $arrivals,
            'later_arrivals' => count($board['arrivals']) - count($arrivals),
            'departures' => $departures,
            'open_requests' => array_sum($this->requests->openCounts($property)),
            'reminders_due' => $this->remindersDue($property, $actorId),
            'in_house' => $board['counts']['occupied'],
        ];
    }

    /** Reminders need the reservation permission; someone who may only see stays still gets the rest of the page. */
    private function remindersDue(PropertyId $property, string $actorId): int
    {
        try {
            return $this->reminders->dueCount($property, $actorId);
        } catch (Refusal) {
            return 0;
        }
    }
}
