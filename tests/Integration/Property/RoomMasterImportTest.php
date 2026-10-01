<?php

declare(strict_types=1);

namespace Tests\Integration\Property;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Migration\RoomMasterImport;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class RoomMasterImportTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const PROPERTY = '01arz3ndektsv4rrffq69g5fav';

    private const TYPES = "code,name,max_adults,max_children,sort_order\nSTD,Standard,2,1,10\nDLX,Deluxe,2,2,20\n";

    private const ROOMS = "number,type_code,floor\n101,STD,1\n102,STD,1\n201,DLX,2\n";

    private string $actorId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Context::add('correlation_id', '01arz3ndektsv4rrffq69g5fat');
        $this->createProperty(self::PROPERTY, 'Hotel');
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY, [RoomCatalogService::MANAGE_PERMISSION]);
        $this->actorId = strtolower((string) $user->getKey());
        app(PropertyContext::class)->activateFromString(self::PROPERTY);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function import(string $types, string $rooms, bool $dryRun, ?int $expectTypes = null, ?int $expectRooms = null): array
    {
        return app(RoomMasterImport::class)->run(PropertyId::fromString(self::PROPERTY), $this->actorId, $types, $rooms, $dryRun, $expectTypes, $expectRooms);
    }

    public function test_a_dry_run_validates_everything_and_keeps_nothing(): void
    {
        $report = $this->import(self::TYPES, self::ROOMS, true, 2, 3);

        self::assertSame('validated', $report['status']);
        self::assertTrue($report['dry_run']);
        self::assertSame([], $report['errors']);
        self::assertSame(['types' => 2, 'rooms' => 3, 'rooms_per_type' => ['DLX' => 1, 'STD' => 2]], $report['totals']);
        self::assertSame(['types' => ['expected' => 2, 'actual' => 2, 'ok' => true], 'rooms' => ['expected' => 3, 'actual' => 3, 'ok' => true]], $report['expectations']);
        self::assertSame(0, DB::table('room_types')->count());
        self::assertSame(0, DB::table('rooms')->count());
        self::assertSame(0, DB::table('audit_entries')->where('action', 'like', 'room%')->count());
        // The dry run leaves evidence, so the rehearsals can be shown.
        self::assertSame('validated', DB::table('import_batches')->value('status'));
    }

    public function test_the_real_run_applies_everything_audited_and_cannot_be_repeated(): void
    {
        $report = $this->import(self::TYPES, self::ROOMS, false, 2, 3);

        self::assertSame('applied', $report['status']);
        self::assertSame(2, DB::table('room_types')->count());
        self::assertSame(3, DB::table('rooms')->count());
        self::assertSame(5, DB::table('audit_entries')->where('reason', 'Cutover import '.$report['batch_id'])->count());
        self::assertSame(['applied'], DB::table('import_batches')->pluck('status')->all());

        $again = $this->import(self::TYPES, self::ROOMS, false);
        self::assertSame('rejected', $again['status']);
        self::assertStringContainsString('already applied', $again['errors'][0]['message']);
        self::assertSame(3, DB::table('rooms')->count());
        // A dry run of the same files is still allowed and now reports the duplicates it would meet.
        self::assertSame('rejected', $this->import(self::TYPES, self::ROOMS, true)['status']);
    }

    public function test_every_bad_row_is_reported_with_its_file_and_line_and_nothing_is_kept(): void
    {
        $types = "code,name,max_adults,max_children,sort_order\nSTD,Standard,2,1,10\nSTD,Duplicate,2,1,10\nX,Bad code,2,1,10\nDLX,Deluxe,0,1,20\nSTE,Suite,4,two,30\n";
        $rooms = "number,type_code,floor\n101,STD,1\n101,STD,1\n102,NOPE,1\n103,STD,way too long floor\n104,STD\n";
        $report = $this->import($types, $rooms, false);

        self::assertSame('rejected', $report['status']);
        $found = array_map(static fn (array $e): string => $e['file'].':'.$e['line'], $report['errors']);
        foreach (['room_types.csv:3', 'room_types.csv:4', 'room_types.csv:5', 'room_types.csv:6', 'rooms.csv:3', 'rooms.csv:4', 'rooms.csv:5', 'rooms.csv:6'] as $expected) {
            self::assertContains($expected, $found);
        }
        self::assertSame(0, DB::table('room_types')->count());
        self::assertSame(0, DB::table('rooms')->count());
        self::assertSame('rejected', DB::table('import_batches')->value('status'));
        self::assertGreaterThan(5, (int) DB::table('import_batches')->value('error_count'));
    }

    public function test_the_signed_off_control_totals_must_match(): void
    {
        $report = $this->import(self::TYPES, self::ROOMS, false, 2, 4);

        self::assertSame('rejected', $report['status']);
        self::assertSame([], $report['errors']);
        self::assertFalse($report['expectations']['rooms']['ok']);
        self::assertSame(3, $report['expectations']['rooms']['actual']);
        self::assertSame(0, DB::table('rooms')->count());
    }

    public function test_the_header_is_checked_and_a_person_without_the_privilege_is_refused(): void
    {
        $report = $this->import("code,name\nSTD,Standard\n", self::ROOMS, true);
        self::assertSame('rejected', $report['status']);
        self::assertStringContainsString('The header must be exactly', $report['errors'][0]['message']);
        self::assertSame('room_types.csv', $report['errors'][0]['file']);

        $stranger = UserRecord::factory()->create();
        $refused = app(RoomMasterImport::class)->run(PropertyId::fromString(self::PROPERTY), strtolower((string) $stranger->getKey()), self::TYPES, self::ROOMS, true, null, null);
        self::assertSame('rejected', $refused['status']);
        self::assertSame(0, DB::table('room_types')->count());
    }

    public function test_batch_records_cannot_be_changed(): void
    {
        $this->import(self::TYPES, self::ROOMS, true);

        foreach ([fn () => DB::table('import_batches')->update(['status' => 'applied']), fn () => DB::table('import_batches')->delete()] as $mutation) {
            try {
                $mutation();
                self::fail('A batch record changed.');
            } catch (QueryException $e) {
                self::assertStringContainsString('import batch record cannot be', $e->getMessage());
            }
        }
    }

    public function test_the_console_command_runs_a_rehearsal_and_then_the_real_import(): void
    {
        $dir = sys_get_temp_dir().'/innsync-import-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/types.csv', self::TYPES);
        file_put_contents($dir.'/rooms.csv', self::ROOMS);
        $args = ['property' => self::PROPERTY, 'actor' => $this->actorId, 'types' => $dir.'/types.csv', 'rooms' => $dir.'/rooms.csv', '--expect-types' => 2, '--expect-rooms' => 3];

        $this->artisan('import:room-master', [...$args, '--dry-run' => true])->assertSuccessful();
        self::assertSame(0, DB::table('rooms')->count());
        $this->artisan('import:room-master', $args)->assertSuccessful();
        self::assertSame(3, DB::table('rooms')->count());
        $this->artisan('import:room-master', $args)->assertFailed();
        $this->artisan('import:room-master', [...$args, 'types' => $dir.'/missing.csv'])->assertFailed();
        self::assertSame(['validated', 'applied', 'rejected'], DB::table('import_batches')->orderBy('created_at')->orderBy('id')->pluck('status')->all());

        unlink($dir.'/types.csv');
        unlink($dir.'/rooms.csv');
        rmdir($dir);
    }
}
