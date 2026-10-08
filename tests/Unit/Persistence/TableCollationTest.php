<?php

declare(strict_types=1);

namespace Tests\Unit\Persistence;

use App\Shared\Infrastructure\Persistence\TableCollation;
use PHPUnit\Framework\TestCase;

/** Which servers cannot take the MySQL 8 collation: MariaDB before 11.4.5. */
final class TableCollationTest extends TestCase
{
    public function test_old_mariadb_needs_the_fallback_and_mysql_and_new_mariadb_do_not(): void
    {
        $this->assertTrue(TableCollation::lacksMysqlCollations('10.11.10-MariaDB'));
        $this->assertTrue(TableCollation::lacksMysqlCollations('5.5.5-10.6.18-MariaDB-log'));
        $this->assertTrue(TableCollation::lacksMysqlCollations('11.4.4-MariaDB'));
        $this->assertFalse(TableCollation::lacksMysqlCollations('11.4.5-MariaDB'));
        $this->assertFalse(TableCollation::lacksMysqlCollations('11.8.2-MariaDB-ubu2404'));
        $this->assertFalse(TableCollation::lacksMysqlCollations('8.0.46-0ubuntu0.24.04.4'));
        $this->assertFalse(TableCollation::lacksMysqlCollations(''));
    }
}
