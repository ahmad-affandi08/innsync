<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\UtcTime;
use App\Shared\Infrastructure\Persistence\Eloquent\BusinessDateCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class TimeStorageTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_the_application_and_database_sessions_run_in_utc(): void
    {
        self::assertSame('UTC', config('app.timezone'));
        self::assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS tz')->tz);
    }

    public function test_a_timestamp_round_trips_as_the_same_instant_whatever_zone_a_session_uses(): void
    {
        Schema::create('tmp_instants', function ($table): void {
            $table->id();
            $table->timestamp('at', precision: 6);
        });

        $instant = UtcTime::parse('2026-10-01T23:30:15.123456Z');
        DB::table('tmp_instants')->insert(['at' => $instant->format('Y-m-d H:i:s.u')]);

        self::assertSame('2026-10-01 23:30:15.123456', DB::selectOne('SELECT at FROM tmp_instants')->at);

        // A reader in another zone sees a different wall clock for the SAME stored instant.
        DB::statement("SET time_zone = '+07:00'");
        self::assertSame('2026-10-02 06:30:15.123456', DB::selectOne('SELECT at FROM tmp_instants')->at);
        DB::statement("SET time_zone = '+00:00'");
        self::assertSame('2026-10-01 23:30:15.123456', DB::selectOne('SELECT at FROM tmp_instants')->at);

        Schema::drop('tmp_instants');
    }

    public function test_a_business_date_is_a_plain_date_that_no_zone_can_shift(): void
    {
        Schema::create('tmp_days', function ($table): void {
            $table->id();
            $table->date('business_date');
        });

        $model = new class extends Model
        {
            protected $table = 'tmp_days';

            public $timestamps = false;

            protected $guarded = [];

            /** @return array<string, class-string> */
            protected function casts(): array
            {
                return ['business_date' => BusinessDateCast::class];
            }
        };

        $created = $model->newQuery()->create(['business_date' => BusinessDate::fromString('2026-10-01')]);

        foreach (['+07:00', '-10:00', '+00:00'] as $zone) {
            DB::statement("SET time_zone = '{$zone}'");
            $read = $model->newQuery()->find($created->getKey());

            self::assertInstanceOf(BusinessDate::class, $read->business_date);
            self::assertSame('2026-10-01', $read->business_date->toString(), $zone);
        }

        // A string is validated, never coerced, and bad input does not reach the database.
        $this->expectException(InvalidArgumentException::class);
        $model->newQuery()->create(['business_date' => '2026-02-30']);
    }

    public function test_the_property_zone_reader_returns_the_stored_zone_or_fails_visibly(): void
    {
        $property = PropertyRecord::query()->create(['name' => 'Hotel', 'timezone' => 'Asia/Jakarta', 'currency_code' => 'IDR']);
        $reader = app(PropertyTimeZoneReader::class);

        self::assertSame('Asia/Jakarta', $reader->forProperty(PropertyId::fromString((string) $property->getKey()))?->identifier());
        self::assertNull($reader->forProperty(PropertyId::fromString(self::PROPERTY)));

        DB::table('properties')->where('id', $property->getKey())->update(['timezone' => 'WIB']);
        $this->expectException(InvalidArgumentException::class);
        $reader->forProperty(PropertyId::fromString((string) $property->getKey()));
    }

    public function test_the_active_property_zone_is_shared_only_to_authenticated_users(): void
    {
        $property = PropertyRecord::query()->create(['name' => 'Hotel', 'timezone' => 'Asia/Jakarta', 'currency_code' => 'IDR']);
        $middleware = app(HandleInertiaRequests::class);

        $request = fn (bool $signedIn, string $propertyId): Request => $this->requestWith($signedIn, $propertyId);
        $zoneFor = static fn (Request $r): mixed => ($middleware->share($r)['timeZone'])();

        self::assertSame('Asia/Jakarta', $zoneFor($request(true, (string) $property->getKey())));
        self::assertNull($zoneFor($request(false, (string) $property->getKey())));
        self::assertNull($zoneFor($request(true, '')));
        self::assertNull($zoneFor($request(true, 'not-a-ulid')));

        DB::table('properties')->where('id', $property->getKey())->update(['timezone' => 'Not/AZone']);
        self::assertNull($zoneFor($request(true, (string) $property->getKey())), 'an invalid stored zone must not break the page');
    }

    private function requestWith(bool $signedIn, string $propertyId): Request
    {
        $request = Request::create('/');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('auth.active_property_id', $propertyId);
        $request->setLaravelSession($session);

        if ($signedIn) {
            $request->setUserResolver(static fn (): object => new \stdClass);
        }

        return $request;
    }
}
