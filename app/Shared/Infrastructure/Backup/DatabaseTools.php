<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Illuminate\Support\Facades\DB;

/** Credentials go to child processes through MYSQL_PWD, never through argv or logs. */
final class DatabaseTools
{
    /** @return array{host: string, port: string, user: string, password: string, database: string} */
    public static function live(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));

        return [
            'host' => (string) ($c['host'] ?? '127.0.0.1'),
            'port' => (string) ($c['port'] ?? '3306'),
            'user' => (string) ($c['username'] ?? ''),
            'password' => (string) ($c['password'] ?? ''),
            'database' => (string) ($c['database'] ?? ''),
        ];
    }

    /** @return list<string> */
    public static function baseTables(string $connection): array
    {
        return array_map(
            static fn (object $row): string => (string) array_values((array) $row)[0],
            DB::connection($connection)->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
        );
    }

    /** @param list<string> $tables @return array<string, int> */
    public static function rowCounts(string $connection, array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::connection($connection)->table($table)->count();
        }

        return $counts;
    }

    public static function scratchConnection(): string
    {
        $scratch = config('backup.restore_test_database');
        $live = self::live()['database'];

        if (! is_string($scratch) || preg_match('/^[a-z0-9_]{1,40}_restore_test$/', $scratch) !== 1 || $scratch === $live) {
            throw BackupFailed::unsafeRestoreTarget();
        }

        config(['database.connections.mysql_restore' => [
            ...(array) config('database.connections.'.config('database.default')),
            'database' => $scratch,
        ]]);
        DB::purge('mysql_restore');

        return 'mysql_restore';
    }
}
