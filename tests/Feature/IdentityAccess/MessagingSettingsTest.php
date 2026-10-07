<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Notifications\WhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Email and WhatsApp set up on screen (owner request 2026-10-07): keys are stored encrypted, never shown again, and each provider gets the request its API expects. */
final class MessagingSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity_access.login_rate_limit_per_minute' => 1000]);
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, ['property.settings.manage']);
    }

    private function save(string $channel, string $provider, array $values, bool $enabled = true)
    {
        return $this->putJson("/property/messaging/{$channel}", ['provider' => $provider, 'enabled' => $enabled, 'values' => $values]);
    }

    public function test_a_key_is_stored_encrypted_and_never_sent_back(): void
    {
        $this->save('email', 'resend', ['api_key' => 're_SECRET_123', 'from_address' => 'hotel@example.com', 'from_name' => 'Hotel'])->assertOk()
            ->assertJsonPath('provider', 'resend')->assertJsonPath('saved_secrets.0', 'api_key')->assertJsonMissingPath('values.api_key');

        $stored = (string) DB::table('messaging_channels')->where('channel', 'email')->value('settings');
        $this->assertStringNotContainsString('re_SECRET_123', $stored);
        $this->assertStringNotContainsString('re_SECRET_123', (string) $this->get('/property/messaging')->getContent());

        // Saving again without the key keeps the saved one.
        $this->save('email', 'resend', ['from_address' => 'new@example.com'])->assertOk();
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'x'], 200)]);
        $this->assertTrue(app(EmailNotifier::class)->notify('guest@example.com', 'Hi', 'Body'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer re_SECRET_123') && str_contains($r['from'], 'new@example.com'));
    }

    public function test_the_screen_lists_every_provider_with_its_fields(): void
    {
        $this->get('/property/messaging')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('foundation/pages/messaging')
            ->where('channels.email.providers', fn ($p) => count($p) === 6)
            ->where('channels.whatsapp.providers.meta_cloud.official', true)
            ->where('channels.whatsapp.providers.fonnte.official', false));
    }

    public function test_each_email_provider_is_called_the_way_its_api_expects(): void
    {
        $cases = [
            ['brevo', ['api_key' => 'k'], 'api.brevo.com/v3/smtp/email'],
            ['mailgun', ['api_key' => 'k', 'domain' => 'mg.example.com', 'region' => 'eu'], 'api.eu.mailgun.net/v3/mg.example.com/messages'],
            ['postmark', ['server_token' => 'k'], 'api.postmarkapp.com/email'],
            ['sendgrid', ['api_key' => 'k'], 'api.sendgrid.com/v3/mail/send'],
        ];

        foreach ($cases as [$provider, $values, $url]) {
            Http::fake(['*' => Http::response([], 202)]);
            $this->save('email', $provider, $values + ['from_address' => 'hotel@example.com'])->assertOk();
            $this->postJson('/property/messaging/email/test', ['destination' => 'me@example.com'])->assertOk()->assertJsonPath('ok', true);
            Http::assertSent(fn (Request $r) => str_contains($r->url(), $url));
        }
    }

    public function test_whatsapp_providers_get_a_normalised_number_and_the_right_call(): void
    {
        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->save('whatsapp', 'meta_cloud', ['phone_number_id' => '123', 'access_token' => 'tok'])->assertOk();
        $this->postJson('/property/messaging/whatsapp/test', ['destination' => '0812-3456-7890'])->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com/v21.0/123/messages') && $r['to'] === '6281234567890' && $r['type'] === 'text');

        $this->save('whatsapp', 'twilio', ['account_sid' => 'AC1', 'auth_token' => 't', 'from_number' => '+14155238886'])->assertOk();
        $this->postJson('/property/messaging/whatsapp/test', ['destination' => '81234567890'])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.twilio.com') && str_contains((string) $r->body(), 'whatsapp%3A%2B6281234567890'));

        $this->save('whatsapp', 'fonnte', ['token' => 'ft'])->assertOk();
        $this->assertTrue(app(WhatsAppNotifier::class)->notify('081234567890', 'Hello'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.fonnte.com/send' && $r->hasHeader('Authorization', 'ft') && $r['target'] === '6281234567890');
    }

    public function test_a_refusing_provider_is_reported_without_its_answer_and_a_gateway_address_must_be_public(): void
    {
        Http::fake(['*' => Http::response(['error' => 'key sk_live_LEAK is invalid'], 401)]);
        $this->save('whatsapp', 'fonnte', ['token' => 'ft'])->assertOk();

        $answer = $this->postJson('/property/messaging/whatsapp/test', ['destination' => '081234567890'])->assertOk()->assertJsonPath('ok', false);
        $this->assertSame('http_401', $answer->json('error'));
        $this->assertStringNotContainsString('sk_live_LEAK', (string) $answer->getContent());

        foreach (['http://example.com/send', 'https://127.0.0.1/send', 'https://10.0.0.5/x', 'https://localhost/x'] as $bad) {
            $this->save('whatsapp', 'webhook', ['url' => $bad])->assertStatus(422);
        }
    }

    public function test_missing_fields_and_other_channels_are_refused_and_turning_off_falls_back(): void
    {
        $this->save('email', 'smtp', ['host' => 'smtp.example.com'])->assertStatus(422);
        $this->save('sms', 'resend', [])->assertNotFound();
        $this->save('email', 'resend', ['api_key' => 'k', 'from_address' => 'hotel@example.com'])->assertOk();
        $this->postJson('/property/messaging/email/off')->assertOk()->assertJsonPath('enabled', false);

        Http::fake();
        app(EmailNotifier::class)->notify('x@example.com', 's', 'b');
        Http::assertNothingSent();
    }

    public function test_only_a_person_who_manages_the_property_may_open_it(): void
    {
        $this->post('/logout');
        $this->signIn(self::A, ['housekeeping.view']);

        $this->get('/property/messaging')->assertForbidden();
        $this->save('email', 'resend', ['api_key' => 'k', 'from_address' => 'a@example.com'])->assertForbidden();
    }
}
