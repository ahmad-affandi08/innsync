<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Shared\Application\Backup\BackupFailed;
use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Infrastructure\Backup\BackupCheck;
use App\Shared\Infrastructure\Backup\BackupCipher;
use App\Shared\Infrastructure\Backup\BackupService;
use App\Shared\Infrastructure\Backup\RestoreVerifier;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use Throwable;

final class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

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

        try {
            DB::statement('CREATE DATABASE IF NOT EXISTS innsync_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        } catch (Throwable) {
            $this->markTestSkipped('The innsync_restore_test scratch database is not available.');
        }

        $this->workDir = sys_get_temp_dir().'/innsync-backup-test-'.bin2hex(random_bytes(6));
        mkdir($this->workDir.'/vault', 0700, true);
        mkdir($this->workDir.'/files/ab', 0700, true);
        file_put_contents($this->workDir.'/files/ab/blob-one', random_bytes(300));
        file_put_contents($this->workDir.'/files/ab/blob-two', random_bytes(50));

        config([
            'backup.path' => $this->workDir.'/vault',
            'backup.encryption_key' => BackupCipher::generateKey(),
            'backup.restore_test_database' => 'innsync_restore_test',
            'backup.keep_last' => null,
            'filesystems.disks.private_files.root' => $this->workDir.'/files',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    public function test_backup_is_encrypted_signed_and_restores_database_and_files(): void
    {
        $result = app(BackupService::class)->run();
        $dir = $this->workDir.'/vault/'.$result['set'];

        self::assertFileExists($dir.'/database.sql.enc');
        self::assertStringNotContainsString('CREATE TABLE', (string) file_get_contents($dir.'/database.sql.enc'));
        self::assertStringNotContainsString('audit_entries', (string) file_get_contents($dir.'/database.sql.enc'));
        self::assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));

        $verified = app(RestoreVerifier::class)->run();

        self::assertSame($result['set'], $verified['set']);
        self::assertSame(2, $verified['files_restored']);
        self::assertGreaterThan(0, $verified['restore_duration_ms']);
        self::assertContains('audit_entries', DB::connection('mysql')->table('information_schema.tables')
            ->where('table_schema', 'innsync_restore_test')->pluck('TABLE_NAME')->all());
        self::assertSame(
            DB::table('migrations')->count(),
            DB::connection('mysql')->table('innsync_restore_test.migrations')->count(),
        );
        $this->assertDatabaseHas('backup_runs', ['kind' => 'backup', 'status' => 'succeeded', 'backup_set' => $result['set']]);
        $this->assertDatabaseHas('backup_runs', ['kind' => 'restore_test', 'status' => 'succeeded']);
    }

    public function test_decrypt_command_recovers_artifacts_for_manual_dr_and_refuses_unsafe_use(): void
    {
        $set = app(BackupService::class)->run()['set'];
        $sql = $this->workDir.'/dump.sql';

        $this->artisan('backup:decrypt', ['set' => $set, 'artifact' => 'database', 'destination' => $sql])->assertSuccessful();
        self::assertStringContainsString('CREATE TABLE `audit_entries`', (string) file_get_contents($sql));
        self::assertSame('0600', substr(sprintf('%o', fileperms($sql)), -4));

        $this->artisan('backup:decrypt', ['set' => $set, 'artifact' => 'database', 'destination' => $sql])->assertFailed();
        $this->artisan('backup:decrypt', ['set' => $set, 'artifact' => 'other', 'destination' => $this->workDir.'/x'])->assertFailed();
        $this->artisan('backup:decrypt', ['set' => '../etc', 'artifact' => 'files', 'destination' => $this->workDir.'/y'])->assertFailed();

        $tar = (string) file_get_contents($this->workDir.'/vault/'.$set.'/files.tar.enc');
        file_put_contents($this->workDir.'/vault/'.$set.'/files.tar.enc', $tar.'x');
        $this->artisan('backup:decrypt', ['set' => $set, 'artifact' => 'files', 'destination' => $this->workDir.'/z'])->assertFailed();
        self::assertFileDoesNotExist($this->workDir.'/z');
    }

    public function test_restored_schema_keeps_append_only_triggers(): void
    {
        app(BackupService::class)->run();
        app(RestoreVerifier::class)->run();

        $triggers = DB::select("SELECT TRIGGER_NAME FROM information_schema.triggers WHERE trigger_schema = 'innsync_restore_test'");

        self::assertNotEmpty($triggers);
        self::assertContains('stored_files_controlled_changes', array_map(static fn ($t) => $t->TRIGGER_NAME, $triggers));
    }

    public function test_tampering_with_any_artifact_or_the_manifest_fails_the_restore_test(): void
    {
        $set = app(BackupService::class)->run()['set'];
        $dir = $this->workDir.'/vault/'.$set;

        $original = (string) file_get_contents($dir.'/files.tar.enc');
        file_put_contents($dir.'/files.tar.enc', substr_replace($original, $original[100] === 'X' ? 'Y' : 'X', 100, 1));
        $this->assertRestoreFails('files.tar.enc checksum mismatch');
        file_put_contents($dir.'/files.tar.enc', $original);

        $manifest = (string) file_get_contents($dir.'/manifest.json');
        file_put_contents($dir.'/manifest.json', str_replace('"files": {', '"files": {"injected": 1,', $manifest));
        $this->assertRestoreFails('manifest signature mismatch');

        $this->assertDatabaseHas('backup_runs', ['kind' => 'restore_test', 'status' => 'failed']);
    }

    public function test_a_different_key_cannot_restore(): void
    {
        app(BackupService::class)->run();
        config(['backup.encryption_key' => BackupCipher::generateKey()]);

        $this->assertRestoreFails('manifest signature mismatch');
    }

    public function test_unsafe_configuration_fails_closed(): void
    {
        foreach ([
            ['backup.path' => null],
            ['backup.path' => base_path('storage/backups')],
            ['backup.path' => base_path()],
            ['backup.path' => 'relative/vault'],
            ['backup.path' => $this->workDir.'/missing'],
        ] as $override) {
            config($override);
            try {
                app(BackupService::class)->run();
                self::fail('Backup ran against an unsafe destination.');
            } catch (BackupFailed) {
                self::assertSame(0, DB::table('backup_runs')->count());
            }
            config(['backup.path' => $this->workDir.'/vault']);
        }

        config(['backup.encryption_key' => null]);
        $this->expectException(BackupFailed::class);
        app(BackupService::class)->run();
    }

    public function test_restore_target_must_be_a_dedicated_scratch_database(): void
    {
        app(BackupService::class)->run();

        foreach (['innsync_test', 'innsync', 'innsync_restore_test; DROP', null] as $target) {
            config(['backup.restore_test_database' => $target]);
            try {
                app(RestoreVerifier::class)->run();
                self::fail('Restore ran against a non-scratch database.');
            } catch (BackupFailed $e) {
                self::assertStringContainsString('scratch database', $e->getMessage());
            }
        }

        self::assertGreaterThan(0, DB::table('migrations')->count());
    }

    public function test_keep_last_prunes_only_the_oldest_sets(): void
    {
        config(['backup.keep_last' => 2]);
        $sets = [];

        for ($i = 0; $i < 3; $i++) {
            $this->travel(1)->minutes();
            $sets[] = app(BackupService::class)->run()['set'];
        }

        self::assertDirectoryDoesNotExist($this->workDir.'/vault/'.$sets[0]);
        self::assertDirectoryExists($this->workDir.'/vault/'.$sets[1]);
        self::assertDirectoryExists($this->workDir.'/vault/'.$sets[2]);
    }

    public function test_health_check_tracks_backup_and_restore_test_freshness(): void
    {
        $check = app(BackupCheck::class);
        self::assertSame(HealthStatus::Down, $check->check()->status);

        app(BackupService::class)->run();
        $result = $check->check();
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertStringContainsString('restore test', $result->summary);

        app(RestoreVerifier::class)->run();
        self::assertSame(HealthStatus::Ok, $check->check()->status);

        $this->travel(27)->hours();
        self::assertSame(HealthStatus::Degraded, $check->check()->status);

        $this->travel(30)->hours();
        self::assertSame(HealthStatus::Down, $check->check()->status);
    }

    public function test_unconfigured_backup_is_degraded_outside_production_and_down_in_production(): void
    {
        config(['backup.path' => null]);
        self::assertSame(HealthStatus::Degraded, app(BackupCheck::class)->check()->status);

        $this->app['env'] = 'production';
        self::assertSame(HealthStatus::Down, app(BackupCheck::class)->check()->status);
    }

    private function assertRestoreFails(string $expectedFragment): void
    {
        try {
            app(RestoreVerifier::class)->run();
            self::fail('Restore test accepted a tampered backup.');
        } catch (BackupFailed $e) {
            self::assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }
}
