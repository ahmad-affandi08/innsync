<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Domain\Time\UtcTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UtcTimeTest extends TestCase
{
    public function test_formats_any_zone_as_utc_with_microseconds_and_z(): void
    {
        $jakarta = new DateTimeImmutable('2026-10-02 06:30:15.123456', new DateTimeZone('Asia/Jakarta'));

        self::assertSame('2026-10-01T23:30:15.123456Z', UtcTime::format($jakarta));
        self::assertSame('UTC', UtcTime::normalize($jakarta)->getTimezone()->getName());
        self::assertSame($jakarta->getTimestamp(), UtcTime::normalize($jakarta)->getTimestamp());
    }

    public function test_parse_requires_an_explicit_offset(): void
    {
        self::assertSame('2026-10-01T23:30:00.000000Z', UtcTime::format(UtcTime::parse('2026-10-02T06:30:00+07:00')));
        self::assertSame('2026-10-01T23:30:00.000000Z', UtcTime::format(UtcTime::parse('2026-10-01T23:30:00Z')));

        foreach (["2026-10-01T23:30:00Z\n", '2026-10-01 23:30:00', '2026-10-01', 'tomorrow', '', 'nonsense-Z'] as $ambiguous) {
            try {
                UtcTime::parse($ambiguous);
                self::fail("{$ambiguous} must be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
