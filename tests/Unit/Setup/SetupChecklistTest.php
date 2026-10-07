<?php

declare(strict_types=1);

namespace Tests\Unit\Setup;

use App\Shared\Application\Setup\SetupChecklist;
use PHPUnit\Framework\TestCase;

final class SetupChecklistTest extends TestCase
{
    /** @return array<string, int> */
    private function ready(): array
    {
        return ['settings' => 1, 'business_date' => 1, 'room_types' => 2, 'rooms' => 40, 'rate_plans' => 1, 'rate_periods' => 3, 'charge_schemes' => 1, 'roles' => 19, 'people' => 4, 'approvals_total' => 11, 'approvals_missing' => 0, 'approvals_single' => 0];
    }

    public function test_a_new_property_has_every_required_step_open(): void
    {
        $steps = SetupChecklist::evaluate([]);
        $progress = SetupChecklist::progress($steps);

        self::assertSame(0, $progress['done']);
        self::assertSame(8, $progress['total']);
        self::assertSame(['profile', 'settings', 'business_date', 'rooms', 'rates', 'tax'], array_slice(array_column($steps, 'key'), 0, 6), 'the order is the order of work');
    }

    public function test_the_required_steps_complete_from_the_data(): void
    {
        $progress = SetupChecklist::progress(SetupChecklist::evaluate($this->ready()));

        self::assertSame($progress['total'], $progress['done']);
    }

    public function test_optional_modules_never_count_against_progress(): void
    {
        $steps = SetupChecklist::evaluate($this->ready());
        $optional = array_filter($steps, static fn (array $s): bool => ! $s['required']);

        self::assertNotEmpty($optional);
        foreach ($optional as $s) {
            self::assertFalse($s['done']);
        }
        self::assertSame(SetupChecklist::progress($steps)['total'], SetupChecklist::progress($steps)['done']);
    }

    public function test_a_step_needs_all_of_its_parts(): void
    {
        $facts = $this->ready();
        $facts['rooms'] = 0;
        $facts['approvals_missing'] = 3;
        $facts['people'] = 1;
        $open = array_column(array_filter(SetupChecklist::evaluate($facts), static fn (array $s): bool => $s['required'] && ! $s['done']), 'key');

        self::assertEqualsCanonicalizing(['rooms', 'approvals', 'people'], $open);
    }

    public function test_an_approval_with_only_one_possible_approver_is_not_done(): void
    {
        $facts = $this->ready();
        $facts['approvals_single'] = 2;
        $open = array_column(array_filter(SetupChecklist::evaluate($facts), static fn (array $s): bool => $s['required'] && ! $s['done']), 'key');

        self::assertSame(['approvals'], array_values($open), 'the person who asks never approves their own request, so one approver is a dead end');
    }

    public function test_a_department_the_property_does_not_use_has_no_step(): void
    {
        $keys = array_column(SetupChecklist::evaluate(['off.hr' => 1, 'off.inventory' => 1, 'off.fnb' => 1]), 'key');

        self::assertNotContains('hr', $keys);
        self::assertNotContains('inventory', $keys);
        self::assertNotContains('fnb', $keys);
        self::assertContains('laundry', $keys);
        self::assertContains('housekeeping', $keys, 'housekeeping is core and never hidden');
    }
}
