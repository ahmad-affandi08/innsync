<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Application\Authorization\ScopedAuthorizer;
use App\Modules\IdentityAccess\Application\Ports\GrantMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** A permission answer is remembered for one web request only, and an access change made meanwhile is seen at once: nobody keeps a right that was just taken away. */
final class RequestGrantMemoTest extends TestCase
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

    public function test_the_same_question_is_asked_of_the_database_once_and_a_revoked_right_is_seen_at_once(): void
    {
        $this->createProperty(self::A, 'A');
        $user = $this->signIn(self::A, ['front-office.reservation.view']);
        $id = (string) $user->getKey();
        $authorizer = app(ScopedAuthorizer::class);
        $memory = app(GrantMemory::class);

        $memory->start();

        try {
            DB::flushQueryLog();
            DB::enableQueryLog();
            self::assertTrue($authorizer->allows($id, 'front-office.reservation.view', self::A));
            $first = count(DB::getQueryLog());
            self::assertTrue($authorizer->allows($id, 'front-office.reservation.view', self::A));
            self::assertSame($first, count(DB::getQueryLog()), 'the second identical question made no query');
            self::assertFalse($authorizer->allows($id, 'finance.audit.view', self::A), 'another permission is a different question');

            DB::table('user_role_assignments')->where('user_id', $id)->update(['is_active' => false]);

            self::assertFalse($authorizer->allows($id, 'front-office.reservation.view', self::A), 'the right that was just taken away is gone in the same request');
        } finally {
            DB::disableQueryLog();
            $memory->stop();
        }
    }

    public function test_nothing_is_remembered_outside_a_web_request(): void
    {
        $this->createProperty(self::A, 'A');
        $user = $this->signIn(self::A, ['front-office.reservation.view']);
        $id = (string) $user->getKey();
        $authorizer = app(ScopedAuthorizer::class);

        self::assertTrue($authorizer->allows($id, 'front-office.reservation.view', self::A));
        DB::table('user_role_assignments')->where('user_id', $id)->update(['is_active' => false]);
        self::assertFalse($authorizer->allows($id, 'front-office.reservation.view', self::A));
    }
}
