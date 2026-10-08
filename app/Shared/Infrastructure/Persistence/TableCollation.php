<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * The text collation new tables are created with. MySQL 8 knows `utf8mb4_0900_ai_ci`; MariaDB before 11.4.5, which most shared hosts run, does not,
 * so there tables use `utf8mb4_unicode_ci` (case and accent insensitive as well). A server that knows the configured collation keeps it, so an
 * existing MySQL installation is unchanged.
 */
final class TableCollation
{
    public const FALLBACK = 'utf8mb4_unicode_ci';

    private static ?string $resolved = null;

    public static function name(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $configured = (string) config('database.connections.'.config('database.default').'.collation', self::FALLBACK);

        return self::$resolved = self::lacksMysqlCollations((string) DB::scalar('select version()')) ? self::FALLBACK : $configured;
    }

    /** True for MariaDB older than 11.4.5, which has no MySQL 8 collations. */
    public static function lacksMysqlCollations(string $version): bool
    {
        if (stripos($version, 'mariadb') === false || preg_match('/(\d+)\.(\d+)\.(\d+)-MariaDB/i', $version, $m) !== 1) {
            return false;
        }

        return version_compare("{$m[1]}.{$m[2]}.{$m[3]}", '11.4.5', '<');
    }
}
