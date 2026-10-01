<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Outbox;

use App\Shared\Application\Observability\SensitiveDataDetected;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OutboxTypesTest extends TestCase
{
    private const AGGREGATE_ID = '01arz3ndektsv4rrffq69g5fax';

    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fat';

    private const EVENT_ID = '01arz3ndektsv4rrffq69g5fay';

    private const PROPERTY_ID = '01arz3ndektsv4rrffq69g5fav';

    public function test_versioned_envelope_contains_required_delivery_metadata(): void
    {
        $message = new OutboxMessage(
            self::EVENT_ID,
            new OutboxEvent(
                PropertyId::fromString(self::PROPERTY_ID),
                'front-office.stay.checked-in',
                self::AGGREGATE_ID,
                2,
                ['room_id' => 'room-101', 'nights' => 2],
            ),
            new DateTimeImmutable('2026-10-01T03:04:05.123456Z'),
            self::CORRELATION_ID,
        );

        self::assertSame([
            'event_id' => self::EVENT_ID,
            'event_type' => 'front-office.stay.checked-in',
            'aggregate_id' => self::AGGREGATE_ID,
            'property_id' => self::PROPERTY_ID,
            'occurred_at' => '2026-10-01T03:04:05.123456Z',
            'correlation_id' => self::CORRELATION_ID,
            'payload_version' => 2,
            'data' => ['room_id' => 'room-101', 'nights' => 2],
        ], $message->envelope());
    }

    public function test_event_rejects_sensitive_or_non_serializable_payload_values(): void
    {
        try {
            new OutboxEvent(
                PropertyId::fromString(self::PROPERTY_ID),
                'finance.payment.recorded',
                self::AGGREGATE_ID,
                1,
                ['nested' => ['api_key' => 'must-not-persist']],
            );
            self::fail('Sensitive outbox data was accepted.');
        } catch (SensitiveDataDetected) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new OutboxEvent(
            PropertyId::fromString(self::PROPERTY_ID),
            'finance.payment.recorded',
            self::AGGREGATE_ID,
            1,
            ['object' => new stdClass],
        );
    }

    public function test_event_contract_rejects_invalid_names_identifiers_versions_and_timezones(): void
    {
        $invalidCases = [
            fn () => new OutboxEvent(
                PropertyId::fromString(self::PROPERTY_ID),
                'INVALID EVENT',
                self::AGGREGATE_ID,
                1,
                [],
            ),
            fn () => new OutboxEvent(
                PropertyId::fromString(self::PROPERTY_ID),
                'valid.event',
                'not-an-ulid',
                1,
                [],
            ),
            fn () => new OutboxEvent(
                PropertyId::fromString(self::PROPERTY_ID),
                'valid.event',
                self::AGGREGATE_ID,
                0,
                [],
            ),
            fn () => new OutboxMessage(
                self::EVENT_ID,
                new OutboxEvent(
                    PropertyId::fromString(self::PROPERTY_ID),
                    'valid.event',
                    self::AGGREGATE_ID,
                    1,
                    [],
                ),
                new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('Asia/Jakarta')),
                self::CORRELATION_ID,
            ),
        ];

        foreach ($invalidCases as $invalidCase) {
            try {
                $invalidCase();
                self::fail('An invalid outbox contract was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
