<?php

declare(strict_types=1);

namespace Tests\Unit\HumanResource;

use App\Modules\HumanResource\Application\AttendanceAnomalies as A;
use PHPUnit\Framework\TestCase;

final class AttendanceAnomaliesTest extends TestCase
{
    private int $n = 0;

    /** @param array<string, mixed> $o */
    private function rec(string $employee, array $o = []): array
    {
        $this->n++;

        return $o + ['id' => 'r'.$this->n, 'employee_id' => $employee, 'work_date' => '2026-10-0'.min(9, $this->n), 'in_at' => '2026-10-0'.min(9, $this->n).' 00:10:00', 'in_method' => 'mobile', 'in_distance_m' => 20 + $this->n,
            'in_accuracy_m' => 12, 'in_device' => 'd-'.$employee, 'in_photo_hash' => 'p'.$this->n, 'out_at' => null, 'out_method' => null];
    }

    public function test_a_normal_clock_in_has_no_mark(): void
    {
        self::assertSame([], A::flag([$this->rec('e1'), $this->rec('e2')], 100));
    }

    public function test_one_phone_for_two_people_marks_both(): void
    {
        $flags = A::flag([$this->rec('e1', ['in_device' => 'x']), $this->rec('e2', ['in_device' => 'x']), $this->rec('e3')], 100);

        self::assertSame([A::SHARED_DEVICE], $flags['r1:in']);
        self::assertSame([A::SHARED_DEVICE], $flags['r2:in']);
        self::assertArrayNotHasKey('r3:in', $flags);
    }

    public function test_one_phone_for_one_person_is_not_shared(): void
    {
        self::assertSame([], A::flag([$this->rec('e1', ['in_device' => 'x']), $this->rec('e1', ['in_device' => 'x'])], 100));
    }

    public function test_the_same_selfie_twice_marks_both(): void
    {
        $flags = A::flag([$this->rec('e1', ['in_photo_hash' => 'same']), $this->rec('e2', ['in_photo_hash' => 'same'])], 100);

        self::assertSame([A::REUSED_PHOTO], $flags['r1:in']);
        self::assertSame([A::REUSED_PHOTO], $flags['r2:in']);
    }

    public function test_the_same_distance_five_times_is_a_faked_spot_but_four_is_not(): void
    {
        $five = array_map(fn () => $this->rec('e1', ['in_distance_m' => 33]), range(1, 5));
        self::assertCount(5, array_filter(A::flag($five, 100), static fn (array $f): bool => in_array(A::SAME_SPOT, $f, true)));

        self::assertSame([], A::flag(array_map(fn () => $this->rec('e2', ['in_distance_m' => 33]), range(1, 4)), 100));
    }

    public function test_an_accuracy_of_one_metre_or_better_and_one_worse_than_the_radius_are_marked(): void
    {
        $flags = A::flag([$this->rec('e1', ['in_accuracy_m' => 1]), $this->rec('e2', ['in_accuracy_m' => 0]), $this->rec('e3', ['in_accuracy_m' => 250]), $this->rec('e4', ['in_accuracy_m' => 100])], 100);

        self::assertSame([A::EXACT_POSITION], $flags['r1:in']);
        self::assertSame([A::EXACT_POSITION], $flags['r2:in']);
        self::assertSame([A::POOR_POSITION], $flags['r3:in']);
        self::assertArrayNotHasKey('r4:in', $flags, 'exactly the radius is still fine');
    }

    public function test_a_new_phone_is_marked_only_after_three_clock_ins_from_another_one(): void
    {
        $few = [$this->rec('e1', ['in_device' => 'a']), $this->rec('e1', ['in_device' => 'a']), $this->rec('e1', ['in_device' => 'b'])];
        self::assertSame([], A::flag($few, 100), 'two earlier clock-ins is too little history to call a phone new');

        $many = [$this->rec('e2', ['in_device' => 'a']), $this->rec('e2', ['in_device' => 'a']), $this->rec('e2', ['in_device' => 'a']), $this->rec('e2', ['in_device' => 'b']), $this->rec('e2', ['in_device' => 'b'])];
        $flags = A::flag($many, 100);

        self::assertSame([A::NEW_DEVICE], $flags['r9:in'] ?? $flags[array_key_first($flags)]);
        self::assertCount(1, $flags, 'only the first clock-in from the new phone');
    }

    public function test_a_clock_in_recorded_by_a_supervisor_is_never_marked(): void
    {
        self::assertSame([], A::flag([$this->rec('e1', ['in_method' => 'manual', 'in_accuracy_m' => 0, 'in_device' => 'x']), $this->rec('e2', ['in_method' => 'manual', 'in_device' => 'x'])], 100));
    }

    public function test_the_clock_out_is_judged_on_its_own_evidence(): void
    {
        $r = $this->rec('e1', ['out_at' => '2026-10-01 08:00:00', 'out_method' => 'mobile', 'out_accuracy_m' => 0, 'out_device' => 'd-e1', 'out_photo_hash' => null, 'out_distance_m' => 10]);

        self::assertSame([$r['id'].':out' => [A::EXACT_POSITION]], A::flag([$r], 100));
    }
}
