<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Infrastructure\Push\PushEndpoint;
use App\Shared\Infrastructure\Push\Vapid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Notices on devices (web push): a device is kept only for a known push service, the push carries no words, the notice is fetched behind the sign-in by the device it was made for, and a device that is gone is dropped. */
final class WebPushTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const PHONE = 'https://fcm.googleapis.com/fcm/send/phone-token-1';

    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/laptop-token-2';

    private string $userId = '';

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
        $this->userId = strtolower((string) $this->signIn(self::A, [])->id);
    }

    private function switchOn(): void
    {
        config(['push.vapid_private_key' => Vapid::generate()['private'], 'push.vapid_subject' => 'mailto:hotel@example.com']);
    }

    public function test_it_is_off_until_the_keys_are_made_and_nothing_is_kept_or_sent_while_off(): void
    {
        $this->getJson('/push/config')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('public_key', null);
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertStatus(409);

        Http::fake();
        $this->assertFalse(app(PushNotifier::class)->enabled());
        $this->assertSame(0, app(PushNotifier::class)->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/approvals'));
        Http::assertNothingSent();
    }

    public function test_a_device_is_kept_only_for_a_known_push_service_and_never_in_the_clear(): void
    {
        $this->switchOn();
        $config = $this->getJson('/push/config')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('subscribed', false);
        $this->assertSame(Vapid::fromConfig()->publicKey(), $config->json('public_key'));

        foreach (['https://evil.example/push', 'http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com.evil.example/x'] as $bad) {
            $this->postJson('/push/devices', ['endpoint' => $bad])->assertStatus(422);
        }

        $this->postJson('/push/devices', ['endpoint' => self::PHONE, 'label' => 'Chrome · Android'])->assertCreated();
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertCreated();
        $this->assertSame(1, DB::table('push_devices')->count());
        $this->assertStringNotContainsString('phone-token-1', json_encode(DB::table('push_devices')->first(), JSON_THROW_ON_ERROR));
        $this->getJson('/push/config?endpoint='.urlencode(self::PHONE))->assertJsonPath('subscribed', true);

        config(['push.max_devices' => 2]);
        $this->postJson('/push/devices', ['endpoint' => self::LAPTOP])->assertCreated();
        $this->postJson('/push/devices', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/third'])->assertStatus(409);

        $this->deleteJson('/push/devices', ['endpoint' => self::LAPTOP])->assertOk();
        $this->assertSame(1, DB::table('push_devices')->count());
    }

    public function test_the_push_is_empty_and_signed_and_each_device_fetches_its_own_notice_once(): void
    {
        $this->switchOn();
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertCreated();
        $this->postJson('/push/devices', ['endpoint' => self::LAPTOP])->assertCreated();
        Http::fake(['*' => Http::response('', 201)]);

        $told = app(PushNotifier::class)->notify(PropertyId::fromString(self::A), [$this->userId, strtoupper($this->userId)], 'Approval waiting', 'An approval request is waiting.', '/approvals');

        $this->assertSame(2, $told);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === self::PHONE && $r->body() === '' && str_starts_with($r->header('Authorization')[0], 'vapid t=') && $r->header('TTL')[0] === '3600' && ! str_contains($r->body(), 'Approval'));

        // Each device gets the notice once; a second push with nothing waiting finds nothing.
        $this->getJson('/push/next', ['X-Push-Endpoint' => self::PHONE])->assertOk()->assertJsonPath('notice', ['title' => 'Approval waiting', 'body' => 'An approval request is waiting.', 'url' => '/approvals']);
        $this->getJson('/push/next', ['X-Push-Endpoint' => self::PHONE])->assertOk()->assertJsonPath('notice', null);
        $this->getJson('/push/next', ['X-Push-Endpoint' => self::LAPTOP])->assertOk()->assertJsonPath('notice.title', 'Approval waiting');
        $this->getJson('/push/next', ['X-Push-Endpoint' => 'https://fcm.googleapis.com/fcm/send/unknown'])->assertOk()->assertJsonPath('notice', null);
        $this->getJson('/push/next')->assertOk()->assertJsonPath('notice', null);
    }

    public function test_someone_else_cannot_fetch_the_notice_of_a_device_and_a_notice_must_point_inside_the_application(): void
    {
        $this->switchOn();
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertCreated();
        Http::fake(['*' => Http::response('', 201)]);
        $notifier = app(PushNotifier::class);

        $this->assertSame(0, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', 'https://evil.example/'));
        $this->assertSame(0, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '//evil.example/'));
        $this->assertSame(1, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/approvals'));

        $this->post('/logout');
        $this->signIn(self::A, []);
        $this->getJson('/push/next', ['X-Push-Endpoint' => self::PHONE])->assertOk()->assertJsonPath('notice', null);
    }

    public function test_a_device_the_push_service_no_longer_knows_is_dropped_and_a_failing_one_is_kept_for_a_while(): void
    {
        $this->switchOn();
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertCreated();
        $this->postJson('/push/devices', ['endpoint' => self::LAPTOP])->assertCreated();
        Http::fake([self::PHONE => Http::response('', 410), self::LAPTOP => Http::response('', 503)]);

        $this->assertSame(0, app(PushNotifier::class)->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/approvals'));

        $this->assertSame(1, DB::table('push_devices')->count());
        $this->assertSame(1, (int) DB::table('push_devices')->value('failures'));
        $this->assertTrue(PushEndpoint::acceptable(self::LAPTOP));
    }

    public function test_a_person_can_turn_a_kind_of_notice_off_and_is_then_not_told_of_it(): void
    {
        $this->switchOn();
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertCreated();
        Http::fake(['*' => Http::response('', 201)]);
        $notifier = app(PushNotifier::class);

        $this->getJson('/push/config')->assertJsonPath('kinds', ['approval', 'work_order', 'guest_request'])->assertJsonPath('muted', []);
        $this->postJson('/push/preferences', ['kind' => 'nonsense', 'enabled' => false])->assertStatus(422);
        $this->postJson('/push/preferences', ['kind' => 'approval', 'enabled' => false])->assertOk()->assertJsonPath('muted', ['approval']);

        $this->assertSame(0, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/approvals', 'approval'));
        $this->assertSame(1, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/maintenance/work-orders', 'work_order'));
        $this->assertSame(1, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/', 'general'));

        $this->postJson('/push/preferences', ['kind' => 'approval', 'enabled' => true])->assertOk()->assertJsonPath('muted', []);
        $this->assertSame(1, $notifier->notify(PropertyId::fromString(self::A), [$this->userId], 'T', 'B', '/approvals', 'approval'));
    }

    public function test_the_routes_need_a_signed_in_person(): void
    {
        $this->post('/logout');
        $this->getJson('/push/config')->assertUnauthorized();
        $this->postJson('/push/devices', ['endpoint' => self::PHONE])->assertUnauthorized();
        $this->getJson('/push/next')->assertUnauthorized();
    }
}
