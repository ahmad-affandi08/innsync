<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** PERF-06: with the switch on, what a request published is moved along once its response is sent, not only at the next cron tick. */
final class AfterResponseDrainTest extends TestCase
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

    private function publish(): void
    {
        $this->createProperty(self::A, 'A');
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new OutboxEvent(PropertyId::fromString(self::A), 'test.nothing_listens', '01arz3ndektsv4rrffq69g5faw', 1, ['x' => 1])));
    }

    public function test_a_published_message_is_left_for_the_cron_when_the_switch_is_off(): void
    {
        config(['outbox.after_response' => false]);
        $this->publish();
        app()->terminate();

        self::assertSame(['pending'], DB::table('outbox_messages')->pluck('status')->all());
    }

    public function test_a_published_message_is_moved_along_after_the_response_when_the_switch_is_on(): void
    {
        config(['outbox.after_response' => true]);
        $this->publish();
        app()->terminate();

        self::assertNotSame(['pending'], DB::table('outbox_messages')->pluck('status')->all());
    }
}
