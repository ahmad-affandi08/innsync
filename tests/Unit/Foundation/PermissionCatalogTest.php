<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A permission the code asks for must be in the catalog (`DevelopmentSeeder::PERMISSIONS`): the catalog is what a new property's starting roles and the administrator made by `innsync:create-admin` are built from,
 * so a permission left out of it can never be given on a fresh installation.
 */
final class PermissionCatalogTest extends TestCase
{
    public function test_every_permission_the_code_names_is_in_the_catalog(): void
    {
        $catalog = array_flip(DatabaseDefaultRoleInstaller::catalog());
        $root = dirname(__DIR__, 3);
        $missing = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }

            preg_match_all("/const\s+([A-Z_]+)\s*=\s*'([a-z0-9-]+(?:\.[a-z0-9_-]+){2,})'/", (string) file_get_contents((string) $file), $found, PREG_SET_ORDER);

            foreach ($found as [, $name, $code]) {
                if (preg_match('/PERMISSION|^(VIEW|MANAGE|EXPORT|OPERATE|ISSUE|VOID|ASSET_[A-Z]+|BANK_[A-Z]+|REPORT|PERFORM|RECEIVE|BOOK|REDEEM|ADJUST)$/D', $name) === 1 && ! isset($catalog[$code])) {
                    $missing[$code] = substr((string) $file, strlen($root) + 1);
                }
            }
        }

        foreach (['web', 'webhooks'] as $routes) {
            preg_match_all('/permission:([a-z0-9._,|-]+)/', (string) file_get_contents($root."/routes/{$routes}.php"), $found);

            foreach ($found[1] as $codes) {
                foreach (explode(',', str_replace('|', ',', $codes)) as $code) {
                    if (! isset($catalog[$code])) {
                        $missing[$code] = "routes/{$routes}.php";
                    }
                }
            }
        }

        self::assertSame([], $missing, 'Add these to DevelopmentSeeder::PERMISSIONS (and a migration that creates them for a running property).');
    }
}
