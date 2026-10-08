<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings a database that was installed before the "key" columns became plain columns up to the shape a new installation has. These columns exist only so that a unique index can say "one open
 * bill per table", "one in-house stay per room" and so on, which a plain unique index cannot say about a condition. They used to be STORED generated columns; the migrations were later rewritten to
 * make them plain columns kept by triggers (a generated column is not portable to MariaDB, where the schema must also install). Rewriting a migration changes nothing in a database that already ran it, so
 * such a database still held the generated columns, and the later migrations that set these columns from a trigger (116, 117) would fail on its first write.
 *
 * For each column that is still generated, the column is turned into a plain one (MODIFY keeps the stored values and the unique index) and a pair of triggers keeps it up to date. A column that is
 * already plain, which is every new installation, is left alone, so this migration does nothing there.
 */
return new class extends Migration
{
    /**
     * @return list<array{table: string, column: string, type: string, not_null: bool, expression: string}> `expression` computes the value from the row (as `NEW.` inside a trigger)
     */
    public function specs(): array
    {
        return [
            ['table' => 'stays', 'column' => 'in_house_room_key', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status = 'in_house' THEN {r}room_id ELSE NULL END"],
            ['table' => 'housekeeping_tasks', 'column' => 'active_room_key', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status IN ('open', 'assigned', 'in_progress') THEN {r}room_id ELSE NULL END"],
            ['table' => 'laundry_orders', 'column' => 'active_barcode_key', 'type' => 'VARCHAR(80)', 'not_null' => false, 'expression' => "CASE WHEN {r}status IN ('delivered', 'cancelled', 'claimed') THEN NULL ELSE CONCAT({r}property_id, '|', {r}barcode) END"],
            ['table' => 'booking_policies', 'column' => 'scope_key', 'type' => 'VARCHAR(40)', 'not_null' => true, 'expression' => "CONCAT(COALESCE({r}rate_plan_id, '*'), '|', COALESCE({r}source, '*'))"],
            ['table' => 'cashier_shifts', 'column' => 'open_cashier_key', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status = 'open' THEN {r}cashier_id ELSE NULL END"],
            ['table' => 'room_service_flags', 'column' => 'open_key', 'type' => 'VARCHAR(60)', 'not_null' => false, 'expression' => 'CASE WHEN {r}ended_at IS NULL THEN CONCAT({r}room_id, {r}kind) ELSE NULL END'],
            ['table' => 'laundry_treatments', 'column' => 'active_express_key', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}kind = 'express' AND {r}is_active = 1 THEN {r}property_id ELSE NULL END"],
            ['table' => 'linen_par_levels', 'column' => 'scope_key', 'type' => 'VARCHAR(46)', 'not_null' => true, 'expression' => "COALESCE({r}room_type_id, CONCAT('area:', {r}area))"],
            ['table' => 'fin_petty_settlements', 'column' => 'pending_fund_key', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status = 'submitted' THEN {r}fund_id ELSE NULL END"],
            ['table' => 'fnb_bills', 'column' => 'open_table_id', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status = 'open' THEN {r}table_id ELSE NULL END"],
            ['table' => 'fnb_cashier_shifts', 'column' => 'open_cashier_id', 'type' => 'CHAR(26)', 'not_null' => false, 'expression' => "CASE WHEN {r}status = 'open' THEN {r}cashier_id ELSE NULL END"],
        ];
    }

    public function up(): void
    {
        foreach ($this->specs() as $spec) {
            $this->converge($spec['table'], $spec['column'], $spec['type'], $spec['not_null'], $spec['expression']);
        }
    }

    public function down(): void
    {
        // A plain column kept by triggers is not turned back into a generated one: it is the shape every new installation has.
    }

    /** Turns one generated column into a plain one kept by triggers. Does nothing when the column is not generated. @return bool whether anything was changed */
    public function converge(string $table, string $column, string $type, bool $notNull, string $expression): bool
    {
        $generated = DB::selectOne(
            'SELECT generation_expression AS expression FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        );

        if ($generated === null || trim((string) ($generated->expression ?? '')) === '') {
            return false;
        }

        // MODIFY on a stored generated column makes it a plain one and keeps what it holds and the unique index on it.
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} ".($notNull ? 'NOT NULL' : 'NULL'));

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            $name = "{$table}_{$column}_sync_{$suffix}";
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW SET NEW.`{$column}` = ".str_replace('{r}', 'NEW.', $expression));
        }

        return true;
    }
};
