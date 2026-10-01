<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Integration;

use App\Shared\Application\Integration\InnSyncWebhookProtocol;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class InnSyncWebhookProtocolTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_value_1234567890';

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01 03:00:00', new DateTimeZone('UTC'));
    }

    private function header(string $body, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $t = $timestamp ?? $this->now()->getTimestamp();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    public function test_a_correct_signature_within_the_window_is_accepted(): void
    {
        $body = '{"event":"payment.paid","amount":150000}';

        self::assertTrue((new InnSyncWebhookProtocol)->verify([self::SECRET], ['x-innsync-signature' => $this->header($body)], $body, $this->now()));
    }

    public function test_any_change_to_the_body_the_secret_or_the_timestamp_is_refused(): void
    {
        $protocol = new InnSyncWebhookProtocol(300);
        $body = '{"event":"payment.paid","amount":150000}';
        $good = $this->header($body);

        self::assertFalse($protocol->verify([self::SECRET], ['x-innsync-signature' => $good], '{"event":"payment.paid","amount":1}', $this->now()), 'tampered body');
        self::assertFalse($protocol->verify(['another-secret'], ['x-innsync-signature' => $good], $body, $this->now()), 'wrong secret');
        self::assertFalse($protocol->verify([self::SECRET], ['x-innsync-signature' => $this->header($body, timestamp: $this->now()->getTimestamp() - 301)], $body, $this->now()), 'stale');
        self::assertFalse($protocol->verify([self::SECRET], ['x-innsync-signature' => $this->header($body, timestamp: $this->now()->getTimestamp() + 301)], $body, $this->now()), 'future');
        self::assertTrue($protocol->verify([self::SECRET], ['x-innsync-signature' => $this->header($body, timestamp: $this->now()->getTimestamp() - 299)], $body, $this->now()), 'inside the window');
        // Re-using a valid signature with a different timestamp must not verify: the timestamp is signed.
        self::assertFalse($protocol->verify([self::SECRET], ['x-innsync-signature' => preg_replace('/t=\d+/', 't='.($this->now()->getTimestamp() - 5), $good)], $body, $this->now()));
    }

    public function test_a_previous_secret_still_verifies_during_rotation_and_no_secret_never_verifies(): void
    {
        $protocol = new InnSyncWebhookProtocol;
        $body = '{}';

        self::assertTrue($protocol->verify(['new-secret', self::SECRET], ['x-innsync-signature' => $this->header($body)], $body, $this->now()));
        self::assertFalse($protocol->verify([], ['x-innsync-signature' => $this->header($body)], $body, $this->now()));
    }

    public function test_several_signatures_are_allowed_and_malformed_headers_are_refused(): void
    {
        $protocol = new InnSyncWebhookProtocol;
        $body = '{}';
        $t = $this->now()->getTimestamp();
        $good = hash_hmac('sha256', "{$t}.{$body}", self::SECRET);
        $other = str_repeat('0', 64);

        self::assertTrue($protocol->verify([self::SECRET], ['x-innsync-signature' => "t={$t},v1={$other},v1={$good}"], $body, $this->now()));

        foreach (['', 'garbage', "t={$t}", "t={$t},v1=short", "t=abc,v1={$good}", "t={$t},v2={$good}", "t={$t},v1={$good} ", "v1={$good},t={$t}"] as $bad) {
            self::assertFalse($protocol->verify([self::SECRET], ['x-innsync-signature' => $bad], $body, $this->now()), $bad);
        }

        self::assertFalse($protocol->verify([self::SECRET], [], $body, $this->now()));
    }

    public function test_the_event_id_must_be_present_and_well_formed(): void
    {
        $protocol = new InnSyncWebhookProtocol;

        self::assertSame('evt_123456789', $protocol->eventId(['x-innsync-event-id' => 'evt_123456789'], ''));
        self::assertNull($protocol->eventId([], ''));
        self::assertNull($protocol->eventId(['x-innsync-event-id' => 'short'], ''));
        self::assertNull($protocol->eventId(['x-innsync-event-id' => "evt_12345678\n"], ''));
        self::assertNull($protocol->eventId(['x-innsync-event-id' => str_repeat('a', 129)], ''));
    }
}
