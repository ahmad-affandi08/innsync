<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

final class BackupCatalog
{
    /** @return list<string> backup set names, oldest first (names start with a UTC timestamp) */
    public static function sets(string $path): array
    {
        $sets = array_values(array_filter(
            scandir($path) ?: [],
            static fn (string $n): bool => preg_match('/^\d{8}T\d{6}Z-[a-z0-9]{8}$/', $n) === 1
                && is_file($path.'/'.$n.'/manifest.json'),
        ));
        sort($sets);

        return $sets;
    }
}
