<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Tenancy\DepartmentScope;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DepartmentScopeTest extends TestCase
{
    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    public function test_a_department_has_a_stable_ulid_that_is_its_own_in_each_property(): void
    {
        $a = PropertyId::fromString(self::A);
        $b = PropertyId::fromString(self::B);
        $ids = [];

        foreach (DepartmentScope::DEPARTMENTS as $department) {
            $id = DepartmentScope::idFor($a, $department);
            self::assertMatchesRegularExpression('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/D', $id);
            self::assertSame($id, DepartmentScope::idFor($a, $department), 'the same property and department give the same id');
            self::assertNotSame($id, DepartmentScope::idFor($b, $department), 'another property gives another id');
            self::assertSame($department, DepartmentScope::departmentOf($a, $id));
            self::assertNull(DepartmentScope::departmentOf($b, $id), 'an id of one property stands for nothing in another');
            $ids[] = $id;
        }

        self::assertCount(count(DepartmentScope::DEPARTMENTS), array_unique($ids));
        self::assertNull(DepartmentScope::departmentOf($a, self::B));
    }

    public function test_an_unknown_department_has_no_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DepartmentScope::idFor(PropertyId::fromString(self::A), 'spa');
    }
}
