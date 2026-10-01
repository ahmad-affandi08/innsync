<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Offline;

use App\Shared\Application\Offline\ConflictAction;
use App\Shared\Application\Offline\InvalidOfflineEnvelope;
use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\OfflineOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineEnvelopeTest extends TestCase
{
    private const OP = '01arz3ndektsv4rrffq69g5fa1';

    /** @return array<string, mixed> */
    private function raw(array $override = []): array
    {
        return array_merge([
            'operation_id' => self::OP,
            'type' => 'fnb.pos.sale',
            'property_id' => '01arz3ndektsv4rrffq69g5fav',
            'device_id' => '01arz3ndektsv4rrffq69g5fb2',
            'actor_id' => '01arz3ndektsv4rrffq69g5fc3',
            'client_sequence' => 4,
            'device_time' => '2026-10-01T10:00:00+07:00',
            'base_version' => 12,
            'payload_version' => 1,
            'payload' => ['amount' => 25000],
        ], $override);
    }

    public function test_a_well_formed_envelope_is_parsed_and_normalized(): void
    {
        $envelope = OfflineEnvelope::fromArray($this->raw(['operation_id' => strtoupper(self::OP)]), 65536);

        self::assertSame(self::OP, $envelope->operationId);
        self::assertSame(12, $envelope->baseVersion);
        self::assertSame('2026-10-01T03:00:00.000000Z', $envelope->fingerprint()['device_time']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'bad operation id' => [['operation_id' => 'not-a-ulid'], 'invalid_envelope'];
        yield 'missing type' => [['type' => null], 'invalid_envelope'];
        yield 'uppercase type' => [['type' => 'Fnb.Pos'], 'invalid_envelope'];
        yield 'short type' => [['type' => 'ab'], 'invalid_envelope'];
        yield 'bad device id' => [['device_id' => 'device-1'], 'invalid_envelope'];
        yield 'bad actor id' => [['actor_id' => 'cashier'], 'invalid_envelope'];
        yield 'missing actor id' => [['actor_id' => null], 'invalid_envelope'];
        yield 'bad property id' => [['property_id' => 'x'], 'invalid_envelope'];
        yield 'negative sequence' => [['client_sequence' => -1], 'invalid_envelope'];
        yield 'string sequence' => [['client_sequence' => '1'], 'invalid_envelope'];
        yield 'negative base version' => [['base_version' => -3], 'invalid_envelope'];
        yield 'string base version' => [['base_version' => 'v2'], 'invalid_envelope'];
        yield 'zero payload version' => [['payload_version' => 0], 'invalid_envelope'];
        yield 'huge payload version' => [['payload_version' => 70000], 'invalid_envelope'];
        yield 'payload not an object' => [['payload' => 'text'], 'invalid_envelope'];
        yield 'device time without offset' => [['device_time' => '2026-10-01T10:00:00'], 'invalid_envelope'];
        yield 'device time garbage' => [['device_time' => 'yesterday'], 'invalid_envelope'];
        yield 'oversized payload' => [['payload' => ['blob' => str_repeat('x', 70000)]], 'payload_too_large'];
        yield 'card number' => [['payload' => ['card_number' => '4111111111111111']], 'sensitive_payload'];
        yield 'nested identity document' => [['payload' => ['guest' => ['passport_number' => 'X1']]], 'sensitive_payload'];
        yield 'secret' => [['payload' => ['secret' => 's']], 'sensitive_payload'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalid')]
    public function test_malformed_or_dangerous_input_is_refused_with_a_stable_code(array $override, string $code): void
    {
        try {
            OfflineEnvelope::fromArray($this->raw($override), 65536);
            self::fail('Expected the envelope to be refused.');
        } catch (InvalidOfflineEnvelope $exception) {
            self::assertSame($code, $exception->reasonCode);
        }
    }

    public function test_the_fingerprint_is_identical_on_every_retry_and_changes_with_content(): void
    {
        $a = OfflineEnvelope::fromArray($this->raw(), 65536)->fingerprint();
        $same = OfflineEnvelope::fromArray($this->raw(['device_time' => '2026-10-01T03:00:00Z']), 65536)->fingerprint();
        $other = OfflineEnvelope::fromArray($this->raw(['payload' => ['amount' => 25001]]), 65536)->fingerprint();

        self::assertSame($a, $same, 'the same instant in another notation is the same request');
        self::assertNotSame($a, $other);
    }

    public function test_operation_id_extraction_is_defensive(): void
    {
        self::assertSame(self::OP, OfflineEnvelope::operationIdOf(['operation_id' => strtoupper(self::OP)]));

        foreach ([null, 'x', [], ['operation_id' => 5], ['operation_id' => 'abc'], ['operation_id' => self::OP."\n"]] as $bad) {
            self::assertNull(OfflineEnvelope::operationIdOf($bad));
        }
    }

    public function test_outcomes_round_trip_through_the_stored_result(): void
    {
        foreach ([
            OfflineOutcome::accepted(['sale' => 5], 9),
            OfflineOutcome::conflict('stale_room_state', ConflictAction::Review, 7),
            OfflineOutcome::rejected('closed_shift'),
        ] as $outcome) {
            $restored = OfflineOutcome::fromArray(json_decode((string) json_encode($outcome->toArray()), true));

            self::assertEquals($outcome, $restored);
        }
    }

    public function test_an_outcome_offers_no_overwrite_and_validates_its_codes(): void
    {
        self::assertSame(['accepted', 'conflict', 'rejected'], [OfflineOutcome::ACCEPTED, OfflineOutcome::CONFLICT, OfflineOutcome::REJECTED]);

        foreach (['', 'Has Space', 'UPPER', str_repeat('a', 70)] as $badCode) {
            try {
                OfflineOutcome::rejected($badCode);
                self::fail("'{$badCode}' must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        OfflineOutcome::fromArray(['status' => 'overwritten']);
    }
}
