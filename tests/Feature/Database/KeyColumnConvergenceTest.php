<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * A database installed before the "key" columns became plain columns still holds them as generated columns; migration 132 turns them into plain columns kept by triggers, and does nothing to a
 * database that already has the plain ones (every new installation).
 */
final class KeyColumnConvergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    private function migration(): object
    {
        return require base_path('database/migrations/0001_01_01_000132_converge_generated_key_columns.php');
    }

    private function triggerCount(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.triggers WHERE trigger_schema = DATABASE()')->n;
    }

    public function test_a_new_installation_has_no_generated_key_column_so_the_migration_changes_nothing(): void
    {
        $before = $this->triggerCount();

        foreach ($this->migration()->specs() as $spec) {
            self::assertFalse($this->migration()->converge($spec['table'], $spec['column'], $spec['type'], $spec['not_null'], $spec['expression']), "{$spec['table']}.{$spec['column']} is plain already");
            self::assertSame('', (string) DB::selectOne('SELECT generation_expression AS g FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$spec['table'], $spec['column']])->g);
        }

        $this->migration()->up();
        self::assertSame($before, $this->triggerCount());
    }

    public function test_every_key_column_the_migration_names_exists_with_its_unique_index(): void
    {
        foreach ($this->migration()->specs() as $spec) {
            $indexed = DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND non_unique = 0', [$spec['table'], $spec['column']])->n;
            self::assertSame(1, (int) $indexed, "{$spec['table']}.{$spec['column']} has one unique index");
        }
    }

    public function test_a_generated_column_becomes_a_plain_one_that_keeps_its_values_its_unique_index_and_follows_the_row(): void
    {
        $spec = ['table' => 'converge_demo', 'column' => 'open_key', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status = 'open' THEN {r}room_id ELSE NULL END"];

        try {
            DB::statement("CREATE TABLE converge_demo (id INT PRIMARY KEY, status VARCHAR(10) NOT NULL, room_id CHAR(26) NOT NULL, open_key CHAR(26) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN room_id ELSE NULL END) STORED, UNIQUE KEY demo_one_open (open_key))");
            DB::table('converge_demo')->insert(['id' => 1, 'status' => 'open', 'room_id' => 'room-a']);
            DB::table('converge_demo')->insert(['id' => 2, 'status' => 'closed', 'room_id' => 'room-a']);

            self::assertTrue($this->migration()->converge($spec['table'], $spec['column'], $spec['type'], $spec['not_null'], $spec['expression']));
            self::assertSame('', (string) DB::selectOne("SELECT generation_expression AS g FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'converge_demo' AND column_name = 'open_key'")->g);
            self::assertSame(['room-a', null], DB::table('converge_demo')->orderBy('id')->pluck('open_key')->all(), 'what the column held is kept');

            // The trigger sets it on a new row and again when the row changes, and the unique index still says one open row per room.
            DB::table('converge_demo')->insert(['id' => 3, 'status' => 'open', 'room_id' => 'room-b']);
            self::assertSame('room-b', DB::table('converge_demo')->where('id', 3)->value('open_key'));

            try {
                DB::table('converge_demo')->insert(['id' => 4, 'status' => 'open', 'room_id' => 'room-a']);
                self::fail('A second open row for the same room must be refused.');
            } catch (QueryException $e) {
                self::assertSame('23000', $e->getCode());
            }

            DB::table('converge_demo')->where('id', 1)->update(['status' => 'closed']);
            self::assertNull(DB::table('converge_demo')->where('id', 1)->value('open_key'));
            DB::table('converge_demo')->where('id', 4 - 2)->update(['status' => 'open']);
            self::assertSame('room-a', DB::table('converge_demo')->where('id', 2)->value('open_key'));

            self::assertFalse($this->migration()->converge($spec['table'], $spec['column'], $spec['type'], $spec['not_null'], $spec['expression']), 'a second run changes nothing');
        } finally {
            DB::statement('DROP TABLE IF EXISTS converge_demo');
        }
    }

    public function test_a_not_null_key_column_stays_not_null(): void
    {
        $spec = ['table' => 'converge_demo2', 'column' => 'scope_key', 'type' => 'VARCHAR(40)', 'not_null' => true, 'expression' => "CONCAT(COALESCE({r}a, '*'), '|', COALESCE({r}b, '*'))"];

        try {
            DB::statement("CREATE TABLE converge_demo2 (id INT PRIMARY KEY, a CHAR(5) NULL, b CHAR(5) NULL, scope_key VARCHAR(40) GENERATED ALWAYS AS (CONCAT(COALESCE(a, '*'), '|', COALESCE(b, '*'))) STORED NOT NULL, UNIQUE KEY demo2_scope (scope_key))");
            DB::table('converge_demo2')->insert(['id' => 1, 'a' => 'x', 'b' => null]);

            self::assertTrue($this->migration()->converge($spec['table'], $spec['column'], $spec['type'], $spec['not_null'], $spec['expression']));
            self::assertSame('x|*', DB::table('converge_demo2')->value('scope_key'));
            DB::table('converge_demo2')->insert(['id' => 2, 'a' => null, 'b' => 'y']);
            self::assertSame('*|y', DB::table('converge_demo2')->where('id', 2)->value('scope_key'));
            self::assertSame('NO', DB::selectOne("SELECT is_nullable AS n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'converge_demo2' AND column_name = 'scope_key'")->n);
        } finally {
            DB::statement('DROP TABLE IF EXISTS converge_demo2');
        }
    }
}
