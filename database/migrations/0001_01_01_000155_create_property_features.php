<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The optional pages of a department (waiting list, groups, day use, spa and activities, gift cards, commissions, door locks, foreign currency) are left out of the menu until a property switches them on.
 * One row for each property and feature; no row means off. A property already running keeps what it uses: a feature that has data (or, for door locks and foreign currency, that was switched on) is switched on here,
 * so no page a hotel works with disappears from its menu. Only the menu changes; the pages, their permissions and their data are as they were.
 */
return new class extends Migration
{
    /** feature => [table, extra condition on the table's rows (a column that is true), or null for any row] */
    private const IN_USE = [
        'waitlist' => ['waitlist_entries', null],
        'groups' => ['reservation_groups', null],
        'day-use' => ['day_use_rates', null],
        'activities' => ['activity_services', null],
        'gift-cards' => ['gift_cards', null],
        'commissions' => ['commission_partners', null],
        'door-locks' => ['door_lock_settings', 'enabled'],
        'foreign-currency' => ['foreign_payment_settings', 'enabled'],
    ];

    public function up(): void
    {
        Schema::create('property_features', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('feature', 32);
            $table->boolean('enabled')->default(false);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['property_id', 'feature']);
        });

        foreach (self::IN_USE as $feature => [$source, $flag]) {
            $query = DB::table($source)->select('property_id')->distinct();

            if ($flag !== null) {
                $query->where($flag, true);
            }

            foreach ($query->pluck('property_id') as $propertyId) {
                DB::table('property_features')->insert(['property_id' => $propertyId, 'feature' => $feature, 'enabled' => true, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_features');
    }
};
