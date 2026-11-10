<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

/** Puts the tasks the departments reported into the order the home page shows. */
final class TodayList
{
    /** The menu key of the department a task key (`<prefix>.<what>`) belongs to. A key with another prefix (`approvals`) belongs to no department and is never switched off. */
    private const DEPARTMENT_OF = ['fo' => 'front-office', 'hk' => 'housekeeping', 'ldy' => 'laundry', 'fnb' => 'fnb', 'kitchen' => 'kitchen', 'mtc' => 'maintenance', 'hr' => 'hr', 'inv' => 'inventory', 'fin' => 'finance'];

    public const LIMIT = 12;

    /**
     * Leaves out a count of zero and the work of a department the property does not use, puts the late items first and then the larger counts, and keeps the first twelve.
     *
     * @param  list<array{key: string, count: int, href: string, urgent?: bool}>  $tasks
     * @param  list<string>  $disabled  menu keys of the departments the property switched off
     * @return list<array{key: string, count: int, href: string, urgent: bool}>
     */
    public static function arrange(array $tasks, array $disabled): array
    {
        $list = [];

        foreach ($tasks as $task) {
            if ($task['count'] > 0 && ! in_array(self::DEPARTMENT_OF[explode('.', $task['key'])[0]] ?? '', $disabled, true)) {
                $list[] = ['key' => $task['key'], 'count' => $task['count'], 'href' => $task['href'], 'urgent' => (bool) ($task['urgent'] ?? false)];
            }
        }

        usort($list, static fn (array $a, array $b): int => [$b['urgent'], $b['count']] <=> [$a['urgent'], $a['count']]);

        return array_slice($list, 0, self::LIMIT);
    }
}
