<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    private static bool $databaseChecked = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Page tests assert server behavior, not compiled assets. `public/build` is not
        // versioned, so tests must pass on a clean checkout before any frontend build.
        $this->withoutVite();
    }

    /**
     * Skips the slow `migrate:fresh` that RefreshDatabase runs once per process when the test database already has every migration. Each test still runs in a transaction that is rolled back, so the
     * database stays empty. After a new migration run `DB_DATABASE=innsync_test php artisan migrate --force` (seconds), or just run the tests: with a migration missing it is rebuilt from scratch as before.
     * `TEST_FRESH_DB=1 php artisan test …` forces a rebuild, for the rare case that a test left data behind or an old migration was edited.
     */
    protected function setUpTraits()
    {
        if (! self::$databaseChecked) {
            self::$databaseChecked = true;

            if (getenv('TEST_FRESH_DB') !== '1' && $this->testDatabaseIsCurrent()) {
                RefreshDatabaseState::$migrated = true;
            }
        }

        return parent::setUpTraits();
    }

    /** Only the dedicated test database is trusted this way (the feature tests refuse any other), and only when every migration file has run. */
    private function testDatabaseIsCurrent(): bool
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            return false;
        }

        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            return false;
        }

        $files = array_map(static fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')) ?: []);

        return $files !== [] && array_diff($files, $ran) === [];
    }
}
