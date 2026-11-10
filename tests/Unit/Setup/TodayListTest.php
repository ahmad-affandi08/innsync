<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use App\Shared\Application\Setup\TodayList;
use PHPUnit\Framework\TestCase;

final class TodayListTest extends TestCase
{
    public function test_late_work_comes_first_then_the_larger_counts_and_zero_is_left_out(): void
    {
        $list = TodayList::arrange([
            ['key' => 'fo.arrivals', 'count' => 3, 'href' => '/a'],
            ['key' => 'hk.dirty', 'count' => 9, 'href' => '/b'],
            ['key' => 'fin.overdue', 'count' => 1, 'href' => '/c', 'urgent' => true],
            ['key' => 'ldy.ready', 'count' => 0, 'href' => '/d'],
        ], []);

        self::assertSame(['fin.overdue', 'hk.dirty', 'fo.arrivals'], array_column($list, 'key'));
        self::assertTrue($list[0]['urgent']);
        self::assertFalse($list[1]['urgent']);
    }

    public function test_the_work_of_a_department_the_property_switched_off_is_left_out_but_approvals_stay(): void
    {
        $list = TodayList::arrange([
            ['key' => 'ldy.ready', 'count' => 2, 'href' => '/laundry'],
            ['key' => 'fin.due', 'count' => 4, 'href' => '/finance/schedule'],
            ['key' => 'approvals.waiting', 'count' => 1, 'href' => '/approvals'],
        ], ['laundry', 'finance']);

        self::assertSame(['approvals.waiting'], array_column($list, 'key'));
    }

    public function test_at_most_twelve_are_kept(): void
    {
        $tasks = [];

        for ($i = 1; $i <= 20; $i++) {
            $tasks[] = ['key' => 'fo.t'.$i, 'count' => $i, 'href' => '/x'];
        }

        $list = TodayList::arrange($tasks, []);

        self::assertCount(12, $list);
        self::assertSame(20, $list[0]['count']);
    }
}
