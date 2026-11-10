<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** A property already running keeps the optional pages it uses (migration 155): a page a hotel works with must not vanish from its menu. */
final class PropertyFeaturesMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_a_feature_that_is_switched_on_or_has_data_stays_on_and_the_others_are_off(): void
    {
        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');
        $user = (string) UserRecord::factory()->create()->getKey();
        $lock = ['vendor' => 'other', 'key_count' => 2, 'lock_version' => 0, 'updated_by' => $user, 'created_at' => now(), 'updated_at' => now()];

        // Door locks are switched on in A; in B the row exists but is off. Foreign currency has no row anywhere.
        DB::table('door_lock_settings')->insert($lock + ['property_id' => self::A, 'enabled' => true]);
        DB::table('door_lock_settings')->insert($lock + ['property_id' => self::B, 'enabled' => false]);

        Schema::drop('property_features');
        (require base_path('database/migrations/0001_01_01_000155_create_property_features.php'))->up();

        self::assertSame(['door-locks'], DB::table('property_features')->where('property_id', self::A)->where('enabled', true)->pluck('feature')->all());
        self::assertSame([], DB::table('property_features')->where('property_id', self::B)->where('enabled', true)->pluck('feature')->all());
    }
}
