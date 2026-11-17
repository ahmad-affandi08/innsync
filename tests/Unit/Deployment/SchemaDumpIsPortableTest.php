<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/** PROD-01: the committed schema dump loads for any database user and on MariaDB, not only for `admin` on MySQL 8. After `php artisan schema:dump` run tools/clean-schema-dump.sh. */
final class SchemaDumpIsPortableTest extends TestCase
{
    public function test_the_dump_has_no_trigger_definer_and_no_collation_only_mysql_8_knows(): void
    {
        $dump = (string) file_get_contents(dirname(__DIR__, 3).'/database/schema/mysql-schema.sql');

        self::assertNotSame('', $dump);
        self::assertStringNotContainsString('DEFINER=', $dump, 'run tools/clean-schema-dump.sh on the dump');
        self::assertStringNotContainsString('utf8mb4_0900_ai_ci', $dump, 'run tools/clean-schema-dump.sh on the dump');
    }
}
