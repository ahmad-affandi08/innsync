<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\HumanResource;

use App\Modules\HumanResource\Application\RosterDutyFilter;
use App\Modules\HumanResource\Application\RosterStore;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdjustableClock;

/** A group notice leaves out only people the roster has off work right now; nobody is silenced by a missing roster. */
final class RosterDutyFilterTest extends TestCase
{
    private const P = '01arz3ndektsv4rrffq69g5fav';

    public function test_people_off_or_outside_their_shift_today_are_left_out_and_everyone_else_stays(): void
    {
        // 2026-10-01 03:00 UTC is 10:00 in Jakarta.
        $filter = $this->filter([
            $this->shift('morning', '2026-10-01', '07:00', '15:00'),
            $this->shift('evening', '2026-10-01', '15:00', '23:00'),
            $this->shift('dayoff', '2026-10-01', null, null, off: true),
            $this->shift('night', '2026-09-30', '23:00', '11:00'),
            $this->shift('gone', '2026-10-01', '15:00', '23:00', status: 'inactive'),
            $this->shift('split', '2026-10-01', '06:00', '09:00', '17:00', '21:00'),
        ]);

        // A split shift counts from its first start to its last end, as the HR dashboard counts it.
        self::assertSame(['MORNING', 'night', 'gone', 'manager', 'split'], $filter->onDuty(PropertyId::fromString(self::P), ['MORNING', 'evening', 'dayoff', 'night', 'gone', 'manager', 'split']));
    }

    public function test_with_no_time_zone_or_no_roster_nobody_is_left_out(): void
    {
        self::assertSame(['a', 'b'], $this->filter([])->onDuty(PropertyId::fromString(self::P), ['a', 'b']));
        self::assertSame(['a'], $this->filter([$this->shift('a', '2026-10-01', null, null, off: true)], zone: false)->onDuty(PropertyId::fromString(self::P), ['a']));
    }

    /** @param list<array<string, mixed>> $entries */
    private function filter(array $entries, bool $zone = true): RosterDutyFilter
    {
        $roster = new class($entries) implements RosterStore
        {
            /** @param list<array<string, mixed>> $entries */
            public function __construct(private array $entries) {}

            public function entriesBetween(PropertyId $property, string $from, string $to, ?string $department): array
            {
                return array_values(array_filter($this->entries, static fn (array $e): bool => $e['work_date'] >= $from && $e['work_date'] <= $to));
            }

            public function addPattern(PropertyId $property, array $row, DateTimeImmutable $at): bool { throw new LogicException('unused'); }

            public function patterns(PropertyId $property, bool $onlyActive): array { throw new LogicException('unused'); }

            public function pattern(PropertyId $property, string $id): ?array { throw new LogicException('unused'); }

            public function updatePattern(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool { throw new LogicException('unused'); }

            public function entry(PropertyId $property, string $employeeId, string $date): ?array { throw new LogicException('unused'); }

            public function putEntry(PropertyId $property, array $row, DateTimeImmutable $at): void { throw new LogicException('unused'); }

            public function removeEntry(PropertyId $property, string $employeeId, string $date): void { throw new LogicException('unused'); }

            public function removeEntriesFrom(PropertyId $property, string $employeeId, string $from): int { throw new LogicException('unused'); }

            public function minimums(PropertyId $property): array { throw new LogicException('unused'); }

            public function setMinimum(PropertyId $property, string $department, string $patternId, int $minimum, string $by, DateTimeImmutable $at): void { throw new LogicException('unused'); }
        };
        $zones = new class($zone) implements PropertyTimeZoneReader
        {
            public function __construct(private bool $zone) {}

            public function forProperty(PropertyId $property): ?PropertyTimeZone
            {
                return $this->zone ? PropertyTimeZone::fromIdentifier('Asia/Jakarta') : null;
            }
        };

        return new RosterDutyFilter($roster, $zones, new AdjustableClock('2026-10-01 03:00:00'));
    }

    /** @return array<string, mixed> */
    private function shift(string $user, string $date, ?string $start, ?string $end, ?string $start2 = null, ?string $end2 = null, bool $off = false, string $status = 'active'): array
    {
        return ['user_id' => $user, 'work_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'starts2_at' => $start2, 'ends2_at' => $end2, 'is_off' => $off, 'employee_status' => $status];
    }
}
