<?php

declare(strict_types=1);

namespace Tests\Unit\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Authorization\RequestGrantMemo;
use PHPUnit\Framework\TestCase;

final class RequestGrantMemoTest extends TestCase
{
    public function test_it_asks_every_time_when_it_is_off(): void
    {
        $memo = new RequestGrantMemo;
        $asked = 0;

        foreach ([1, 2, 3] as $_) {
            $memo->remember('k', function () use (&$asked): bool {
                $asked++;

                return true;
            });
        }

        self::assertSame(3, $asked, 'outside a web request nothing is remembered');
    }

    public function test_it_asks_once_per_question_while_on_and_forgets_when_stopped(): void
    {
        $memo = new RequestGrantMemo;
        $asked = 0;
        $ask = function () use (&$asked): bool {
            $asked++;

            return $asked === 1;
        };

        $memo->start();
        self::assertTrue($memo->remember('a', $ask));
        self::assertTrue($memo->remember('a', $ask), 'the same question gets the remembered answer');
        self::assertFalse($memo->remember('b', $ask), 'another question is asked');
        self::assertSame(2, $asked);

        $memo->stop();
        $memo->start();
        $memo->remember('a', $ask);
        self::assertSame(3, $asked, 'a new request starts with nothing remembered');
    }

    public function test_a_write_to_an_access_table_empties_it_and_a_write_elsewhere_does_not(): void
    {
        $memo = new RequestGrantMemo;
        $asked = 0;
        $ask = function () use (&$asked): bool {
            $asked++;

            return true;
        };

        $memo->start();
        $memo->remember('a', $ask);

        foreach (['select * from `roles`', 'update `folios` set `x` = 1', 'insert into `audit_entries` (`id`) values (?)', 'update `users` set `last_login_at` = ?'] as $statement) {
            $memo->noteStatement($statement);
        }

        $memo->remember('a', $ask);
        self::assertSame(1, $asked, 'reads, other tables and the users table do not matter to a permission answer');

        foreach (['update `user_role_assignments` set `is_active` = ?', 'delete from `role_permissions` where `role_id` = ?', 'insert into `roles` (`id`) values (?)', 'insert ignore into `permissions` (`id`) values (?)', 'update `roles` set `is_active` = 0'] as $write) {
            $memo->remember('a', $ask);
            $memo->noteStatement($write);
            $memo->remember('a', $ask);
        }

        self::assertSame(1 + 5, $asked, 'each of the five writes made the next question go to the database again');
    }
}
