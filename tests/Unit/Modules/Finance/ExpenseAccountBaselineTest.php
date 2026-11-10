<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Finance;

use App\Modules\Finance\Application\ExpenseAccountService;
use PHPUnit\Framework\TestCase;

final class ExpenseAccountBaselineTest extends TestCase
{
    public function test_every_starting_account_passes_the_rules_of_an_account_a_person_could_add(): void
    {
        $codes = [];

        foreach (ExpenseAccountService::BASELINE as [$code, $name, $department, $category]) {
            self::assertSame(1, preg_match('/^[A-Z0-9][A-Z0-9._-]{0,11}$/', $code), "code {$code}");
            self::assertNotSame('', trim($name));
            self::assertLessThanOrEqual(80, mb_strlen($name));
            self::assertContains($department, ExpenseAccountService::DEPARTMENTS, $code);
            self::assertContains($category, ExpenseAccountService::CATEGORIES, $code);
            $codes[] = $code;
        }

        self::assertSame(count($codes), count(array_unique($codes)), 'a code is used once');
    }
}
