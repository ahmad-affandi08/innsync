<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The work order reports (FR-MTC-012): what was reported in a period by status and by the department that reported it; how long the work that was finished took, on average and by
 * priority, and how much of it was done by its deadline; the rooms that broke again and again; and the days rooms could not be sold because of a work order. The cost of repairs is not
 * here yet: it comes with the spare parts and the work of vendors.
 */
final readonly class ReportService
{
    private const MAX_DAYS = 366;

    public function __construct(private WorkOrderStore $store, private MaintenanceAccess $access) {}

    /** @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, string $from, string $to): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not see the maintenance reports.');
        $start = $this->date($from, 'from');
        $end = $this->date($to, 'to');

        if ($end < $start || $start->diff($end)->days >= self::MAX_DAYS) {
            throw Refusal::invalid('Choose a period of at most a year, ending after it starts.', ['from', 'to']);
        }

        $reported = $this->store->reportedBetween($property, $from, $to);
        $byStatus = [];
        $byDepartment = [];
        $rooms = [];

        foreach ($reported as $w) {
            $byStatus[$w['status']] = ($byStatus[$w['status']] ?? 0) + 1;
            $byDepartment[$w['reporter_department']] = ($byDepartment[$w['reporter_department']] ?? 0) + 1;

            if ($w['room_id'] !== null) {
                $rooms[$w['room_id']]['room'] = $w['room_number'];
                $rooms[$w['room_id']]['count'] = ($rooms[$w['room_id']]['count'] ?? 0) + 1;
                $rooms[$w['room_id']]['categories'][$w['category']] = true;
            }
        }

        $durations = [];
        $onTime = 0;
        $done = $this->store->doneBetween($property, $from, $to);

        foreach ($done as $w) {
            $hours = (strtotime((string) $w['done_at'].' UTC') - strtotime((string) $w['reported_at'].' UTC')) / 3600;
            $durations[$w['priority']][] = $hours;
            $onTime += strtotime((string) $w['done_at'].' UTC') <= strtotime((string) $w['due_at'].' UTC') ? 1 : 0;
        }

        $all = array_merge(...array_values($durations ?: [[]]));
        ksort($byDepartment);
        $repeat = array_values(array_filter(array_map(static fn (array $r): array => ['room' => $r['room'], 'count' => $r['count'], 'categories' => array_keys($r['categories'])], $rooms), static fn (array $r): bool => $r['count'] >= 2));
        usort($repeat, static fn (array $a, array $b): int => [$b['count'], $a['room']] <=> [$a['count'], $b['room']]);

        return [
            'from' => $from, 'to' => $to, 'reported' => count($reported),
            'by_status' => $byStatus, 'by_department' => $byDepartment,
            'completion' => [
                'done' => count($done), 'average_hours' => $all === [] ? null : round(array_sum($all) / count($all), 1), 'on_time' => $onTime, 'on_time_percent' => $done === [] ? null : (int) round($onTime * 100 / count($done)),
                'by_priority' => array_map(static fn (string $p): array => ['priority' => $p, 'done' => count($durations[$p] ?? []), 'average_hours' => ($durations[$p] ?? []) === [] ? null : round(array_sum($durations[$p]) / count($durations[$p]), 1)], WorkOrderService::PRIORITIES),
            ],
            'repeat_rooms' => $repeat,
            'unsellable' => $this->unsellable($property, $start, $end),
        ];
    }

    /** The nights each room was off sale for a work order within the period, a block counting until the room went back on sale or its last night. @return array{total_days: int, rooms: list<array{room: string, days: int}>} */
    private function unsellable(PropertyId $property, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $per = [];

        foreach ($this->store->roomBlocksBetween($property, $start->format('Y-m-d'), $end->format('Y-m-d')) as $b) {
            $from = max($start, new DateTimeImmutable((string) $b['from_date'], new DateTimeZone('UTC')));
            $last = new DateTimeImmutable((string) $b['until_date'], new DateTimeZone('UTC'));

            if ($b['released_on'] !== null) {
                $last = min($last, (new DateTimeImmutable((string) $b['released_on'], new DateTimeZone('UTC')))->modify('-1 day'));
            }

            $last = min($last, $end);
            $days = $last < $from ? 0 : (int) $from->diff($last)->days + 1;

            if ($days > 0) {
                $per[$b['room_number']] = ($per[$b['room_number']] ?? 0) + $days;
            }
        }

        arsort($per);

        return ['total_days' => array_sum($per), 'rooms' => array_map(static fn (string $room, int $days): array => ['room' => $room, 'days' => $days], array_keys($per), array_values($per))];
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $d;
    }
}
