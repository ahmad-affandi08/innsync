<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Tenancy\MissingPropertyContext;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Infrastructure\Persistence\Eloquent\PropertyOwnedModel;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class MySqlPropertyScopeTest extends TestCase
{
    use DatabaseMigrations;

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('testing_property_records', function ($table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['property_id', 'name']);
        });

        $this->createProperty(self::PROPERTY_A, 'Property A');
        $this->createProperty(self::PROPERTY_B, 'Property B');
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Schema::dropIfExists('testing_property_records');

        parent::tearDown();
    }

    public function test_mysql_baseline_uses_required_engine_collation_ulids_and_lock_version(): void
    {
        $server = DB::selectOne('SELECT VERSION() AS version');
        $table = DB::selectOne(<<<'SQL'
            SELECT ENGINE AS engine, TABLE_COLLATION AS table_collation
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties'
            SQL);
        $idColumn = DB::selectOne(<<<'SQL'
            SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS character_length
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'id'
            SQL);

        self::assertStringStartsWith('8.0.', $server->version);
        self::assertSame('InnoDB', $table->engine);
        self::assertSame('utf8mb4_0900_ai_ci', $table->table_collation);
        self::assertSame('char', $idColumn->data_type);
        self::assertSame(26, $idColumn->character_length);
        self::assertTrue(Schema::hasColumns('properties', [
            'id',
            'timezone',
            'currency_code',
            'lock_version',
        ]));
        self::assertSame(26, strlen((string) PropertyRecord::query()->firstOrFail()->getKey()));
    }

    public function test_property_owned_queries_fail_closed_and_isolate_each_property(): void
    {
        $context = app(PropertyContext::class);

        try {
            TestingPropertyRecord::query()->count();
            self::fail('A property-owned query ran without an active property context.');
        } catch (MissingPropertyContext) {
            self::assertTrue(true);
        }

        $recordA = $context->run(
            PropertyId::fromString(self::PROPERTY_A),
            fn (): TestingPropertyRecord => TestingPropertyRecord::query()->create(['name' => 'A']),
        );
        $recordB = $context->run(
            PropertyId::fromString(self::PROPERTY_B),
            fn (): TestingPropertyRecord => TestingPropertyRecord::query()->create(['name' => 'B']),
        );

        self::assertSame(self::PROPERTY_A, $recordA->property_id);
        self::assertSame(self::PROPERTY_B, $recordB->property_id);
        self::assertSame(
            ['A'],
            $context->run(
                PropertyId::fromString(self::PROPERTY_A),
                fn (): array => TestingPropertyRecord::query()->pluck('name')->all(),
            ),
        );
        self::assertSame(
            ['B'],
            $context->run(
                PropertyId::fromString(self::PROPERTY_B),
                fn (): array => TestingPropertyRecord::query()->pluck('name')->all(),
            ),
        );
    }

    public function test_cross_property_writes_and_property_reassignment_are_rejected(): void
    {
        $context = app(PropertyContext::class);
        $context->activate(PropertyId::fromString(self::PROPERTY_A));

        $mismatched = new TestingPropertyRecord(['name' => 'Wrong property']);
        $mismatched->property_id = self::PROPERTY_B;

        try {
            $mismatched->save();
            self::fail('A cross-property insert was accepted.');
        } catch (PropertyScopeViolation) {
            self::assertTrue(true);
        }

        $record = TestingPropertyRecord::query()->create(['name' => 'Original']);
        $record->property_id = self::PROPERTY_B;

        $this->expectException(PropertyScopeViolation::class);

        $record->save();
    }

    public function test_stale_update_raises_an_optimistic_lock_conflict(): void
    {
        $context = app(PropertyContext::class);
        $context->activate(PropertyId::fromString(self::PROPERTY_A));
        $record = TestingPropertyRecord::query()->create(['name' => 'Original']);
        $firstWriter = TestingPropertyRecord::query()->findOrFail($record->getKey());
        $staleWriter = TestingPropertyRecord::query()->findOrFail($record->getKey());

        $firstWriter->name = 'First writer';
        $firstWriter->save();

        self::assertSame(1, $firstWriter->lock_version);

        $staleWriter->name = 'Stale writer';

        $this->expectException(OptimisticLockConflict::class);

        $staleWriter->save();
    }

    private function createProperty(string $id, string $name): void
    {
        $property = new PropertyRecord([
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
        ]);
        $property->id = $id;
        $property->save();
    }
}

#[Guarded([])]
final class TestingPropertyRecord extends PropertyOwnedModel
{
    protected $table = 'testing_property_records';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
        ];
    }
}
