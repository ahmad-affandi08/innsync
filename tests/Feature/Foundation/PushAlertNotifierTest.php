<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Observability\Health\AlertNotifier;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** An alert that is raised or gets worse reaches the people who keep the system running, on their devices; one that is resolved, or a person without the right, does not. */
final class PushAlertNotifierTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    /** @var list<array{users: list<string>, title: string, body: string, kind: string}> */
    public array $sent = [];

    public bool $enabled = true;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_a_raised_alert_reaches_the_people_who_may_change_the_settings_and_nobody_else_and_a_resolved_one_stays_quiet(): void
    {
        $this->createProperty(self::A, 'A');
        $keeper = strtolower((string) $this->signIn(self::A, [PropertySettingsService::MANAGE_PERMISSION])->getKey());
        $this->post('/logout');
        $cook = UserRecord::factory()->create();
        $this->grant($cook, self::A, ['kitchen.board.operate']);

        $test = $this;
        $this->app->instance(PushNotifier::class, new class($test) implements PushNotifier
        {
            public function __construct(private TestCase $test) {}

            public function notify(PropertyId $property, array $userIds, string $title, string $body, string $url, string $kind = 'general'): int
            {
                $this->test->sent[] = ['users' => $userIds, 'title' => $title, 'body' => $body, 'kind' => $kind];

                return count($userIds);
            }

            public function enabled(): bool
            {
                return $this->test->enabled;
            }
        });

        $notifier = $this->app->make(AlertNotifier::class);
        $notifier->notify('raised', 'outbox_backlog', HealthResult::down('Outbox delivery is stalled.'));
        self::assertCount(1, $this->sent);
        self::assertSame([[$keeper], 'system', 'Outbox delivery is stalled.'], [$this->sent[0]['users'], $this->sent[0]['kind'], $this->sent[0]['body']]);

        $notifier->notify('resolved', 'outbox_backlog', HealthResult::ok('Fine.'));
        self::assertCount(1, $this->sent, 'a resolved alert is not pushed');

        $this->enabled = false;
        $notifier->notify('escalated', 'outbox_backlog', HealthResult::down('Outbox delivery is stalled.'));
        self::assertCount(1, $this->sent, 'nothing is pushed while web push is not set up');
    }
}
