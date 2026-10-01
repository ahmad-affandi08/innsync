<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Integration\WebhookReceiptStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class WebhookReceiverTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const SECRET = 'whsec_test_secret_value_1234567890';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
        config(['integrations.providers.testpay' => ['property_id' => self::A, 'webhook_secrets' => [self::SECRET]]]);
    }

    /** @return array<string, string> */
    private function signed(string $body, string $eventId = 'evt_000000001', string $secret = self::SECRET, ?int $at = null): array
    {
        $t = $at ?? time();

        return [
            'X-InnSYnc-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret),
            'X-InnSYnc-Event-Id' => $eventId,
            'Content-Type' => 'application/json',
        ];
    }

    private function deliver(string $provider, string $body, array $headers)
    {
        return $this->call('POST', "/api/webhooks/{$provider}", [], [], [], $this->transform($headers), $body);
    }

    /** @param array<string, string> $headers */
    private function transform(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    public function test_a_valid_callback_is_stored_encrypted_once_and_handed_to_the_outbox_by_reference(): void
    {
        $body = '{"event":"payment.paid","reference":"INV-1","payer":"Budi"}';

        $this->deliver('testpay', $body, $this->signed($body))
            ->assertStatus(202)
            ->assertExactJson(['status' => 'accepted'])
            ->assertHeader('X-Correlation-ID');

        $receipt = DB::table('webhook_receipts')->first();
        self::assertSame(self::A, $receipt->property_id);
        self::assertStringNotContainsString('Budi', $receipt->payload);
        self::assertSame($body, app(WebhookReceiptStore::class)->body(PropertyId::fromString(self::A), $receipt->id));

        $message = DB::table('outbox_messages')->where('event_type', 'integration.webhook.received')->first();
        self::assertNotNull($message);
        self::assertStringNotContainsString('Budi', json_encode($message));
    }

    public function test_a_redelivery_of_the_same_event_is_acknowledged_and_changes_nothing(): void
    {
        $body = '{"event":"payment.paid"}';

        $this->deliver('testpay', $body, $this->signed($body))->assertStatus(202)->assertExactJson(['status' => 'accepted']);
        $this->deliver('testpay', $body, $this->signed($body))->assertStatus(202)->assertExactJson(['status' => 'duplicate']);

        self::assertSame(1, DB::table('webhook_receipts')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'integration.webhook.received')->count());
    }

    public function test_bad_signatures_unknown_providers_stale_and_unsigned_requests_get_the_same_uniform_401(): void
    {
        $body = '{"event":"payment.paid"}';
        $cases = [
            'wrong secret' => ['testpay', $body, $this->signed($body, secret: 'not-the-secret')],
            'tampered body' => ['testpay', '{"event":"payment.paid","x":1}', $this->signed($body)],
            'stale' => ['testpay', $body, $this->signed($body, at: time() - 3600)],
            'unsigned' => ['testpay', $body, ['X-InnSYnc-Event-Id' => 'evt_000000009']],
            'unknown provider' => ['nobody', $body, $this->signed($body)],
            'missing event id' => ['testpay', $body, array_diff_key($this->signed($body), ['X-InnSYnc-Event-Id' => 1])],
        ];
        $shapes = [];

        foreach ($cases as $name => [$provider, $payload, $headers]) {
            $response = $this->deliver($provider, $payload, $headers);
            $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
            $shapes[$name] = array_keys($response->json('error'));
        }

        self::assertCount(1, array_unique(array_map('json_encode', $shapes)), 'every refusal looks the same to the sender');
        self::assertSame(0, DB::table('webhook_receipts')->count());
        self::assertSame(0, DB::table('outbox_messages')->count());
        // Refusals for a known provider are security events; an unknown provider has no property to attribute them to.
        self::assertSame(5, DB::table('security_events')->where('event_type', 'integration.webhook.refused')->count());
        self::assertStringNotContainsString(self::SECRET, (string) DB::table('security_events')->pluck('metadata')->implode(''));
    }

    public function test_an_oversized_body_is_refused_with_413_before_the_signature_is_checked(): void
    {
        config(['integrations.webhooks.max_body_bytes' => 100]);
        $body = str_repeat('a', 101);

        $this->deliver('testpay', $body, $this->signed($body))->assertStatus(413);
        self::assertSame(0, DB::table('webhook_receipts')->count());
    }

    public function test_a_rotated_secret_is_accepted_while_the_previous_one_is_still_listed(): void
    {
        config(['integrations.providers.testpay.webhook_secrets' => ['new-secret-value-0987654321', self::SECRET]]);
        $body = '{"event":"payment.paid"}';

        $this->deliver('testpay', $body, $this->signed($body))->assertStatus(202);
        $this->deliver('testpay', $body, $this->signed($body, 'evt_000000002', 'new-secret-value-0987654321'))->assertStatus(202);

        config(['integrations.providers.testpay.webhook_secrets' => ['new-secret-value-0987654321']]);
        $this->deliver('testpay', $body, $this->signed($body, 'evt_000000003'))->assertStatus(401);
    }

    public function test_a_provider_without_a_secret_never_accepts_anything(): void
    {
        config(['integrations.providers.testpay.webhook_secrets' => ['']]);
        $body = '{}';

        $this->deliver('testpay', $body, $this->signed($body, secret: ''))->assertStatus(401);
    }

    public function test_receipts_cannot_be_changed_or_deleted(): void
    {
        $body = '{"event":"payment.paid"}';
        $this->deliver('testpay', $body, $this->signed($body))->assertStatus(202);

        foreach ([fn () => DB::table('webhook_receipts')->update(['provider' => 'x']), fn () => DB::table('webhook_receipts')->delete()] as $forbidden) {
            try {
                $forbidden();
                self::fail('A webhook receipt was altered.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_post_is_allowed_and_no_session_or_csrf_is_involved(): void
    {
        $this->get('/api/webhooks/testpay')->assertStatus(405);
        $response = $this->deliver('testpay', '{}', $this->signed('{}', 'evt_000000007'));
        $response->assertStatus(202);
        self::assertNull($response->headers->get('Set-Cookie'));
    }
}
