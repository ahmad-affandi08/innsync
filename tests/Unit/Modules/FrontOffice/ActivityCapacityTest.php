<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\FrontOffice;

use App\Modules\FrontOffice\Application\Activities\ActivityCapacity;
use PHPUnit\Framework\TestCase;

final class ActivityCapacityTest extends TestCase
{
    public function test_a_booking_fits_when_the_guests_together_never_pass_the_capacity(): void
    {
        $existing = [['start' => 600, 'end' => 660, 'guests' => 1], ['start' => 630, 'end' => 690, 'guests' => 1]];

        // Two are in the room between 10:30 and 11:00: one more is one too many there, but fine before 10:00 or after 11:30.
        self::assertFalse(ActivityCapacity::fits($existing, 640, 700, 1, 2));
        self::assertTrue(ActivityCapacity::fits($existing, 540, 600, 1, 2));
        self::assertTrue(ActivityCapacity::fits($existing, 690, 750, 2, 2));
        // The new booking starting before the others and reaching into them counts their start too.
        self::assertFalse(ActivityCapacity::fits($existing, 570, 640, 1, 2));
        // Ending exactly when another starts does not overlap it.
        self::assertTrue(ActivityCapacity::fits([['start' => 600, 'end' => 660, 'guests' => 2]], 540, 600, 2, 2));
    }

    public function test_a_group_larger_than_the_capacity_never_fits(): void
    {
        self::assertFalse(ActivityCapacity::fits([], 600, 660, 3, 2));
        self::assertTrue(ActivityCapacity::fits([], 600, 660, 2, 2));
    }

    public function test_times_are_read_as_minutes_from_midnight(): void
    {
        self::assertSame(870, ActivityCapacity::minutes('14:30'));
        self::assertSame(0, ActivityCapacity::minutes('00:00'));
        self::assertNull(ActivityCapacity::minutes('24:00'));
        self::assertNull(ActivityCapacity::minutes('9:30'));
        self::assertNull(ActivityCapacity::minutes('14:60'));
    }
}
