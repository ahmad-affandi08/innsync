<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\ImmutableEvidencePayload;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImmutableEvidencePayloadTest extends TestCase
{
    public function test_checksum_is_stable_for_equivalent_associative_payloads(): void
    {
        self::assertSame(
            ImmutableEvidencePayload::checksum(['b' => 2, 'a' => ['d' => 4, 'c' => 3]]),
            ImmutableEvidencePayload::checksum(['a' => ['c' => 3, 'd' => 4], 'b' => 2]),
        );
    }

    public function test_unresolved_retention_is_indefinite_and_configured_minimum_is_calculated(): void
    {
        $occurredAt = new CarbonImmutable('2026-10-01 00:00:00', 'UTC');

        self::assertNull(ImmutableEvidencePayload::minimumRetentionUntil($occurredAt, null));
        self::assertSame(
            '2026-10-31',
            ImmutableEvidencePayload::minimumRetentionUntil($occurredAt, '30')?->format('Y-m-d'),
        );
    }

    public function test_invalid_retention_configuration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImmutableEvidencePayload::minimumRetentionUntil(CarbonImmutable::now('UTC'), 0);
    }
}
