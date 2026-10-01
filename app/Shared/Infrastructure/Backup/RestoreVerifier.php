<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;

/** Proves a backup can actually be restored, and measures how long that takes (RTO evidence). */
final class RestoreVerifier
{
    public function __construct(private readonly BackupRunLog $log) {}

    /** @return array<string, mixed> */
    public function run(?string $set = null): array
    {
        $settings = BackupSettings::fromConfig();
        $connection = DatabaseTools::scratchConnection();
        $runId = $this->log->start('restore_test');
        $startedAt = hrtime(true);
        $work = null;
        $details = [];

        try {
            $sets = BackupCatalog::sets($settings->path);
            $set ??= end($sets) ?: null;

            if ($set === null || ! in_array($set, $sets, true)) {
                throw BackupFailed::noBackupSet();
            }

            $dir = $settings->path.'/'.$set;
            $manifest = $this->readManifest($settings, $dir);

            foreach ($manifest['artifacts'] as $name => $hash) {
                if (! is_string($hash) || ! hash_equals($hash, (string) hash_file('sha256', $dir.'/'.$name))) {
                    throw BackupFailed::integrity($name.' checksum mismatch');
                }
            }

            $this->wipe($connection);
            $this->importDump($settings, $dir.'/database.sql.enc');
            $checks = $this->verifyDatabase($connection, $manifest);

            $work = sys_get_temp_dir().'/innsync-restore-'.bin2hex(random_bytes(6));
            mkdir($work, 0700);
            $files = $this->verifyFiles($settings, $dir.'/files.tar.enc', $work, $manifest);

            $details = [
                'tables' => count($manifest['database']['tables']),
                'append_only_checks' => $checks,
                'files_restored' => $files,
                'restore_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
            $this->log->succeed($runId, $set, (int) $manifest['database']['sql_bytes'], $details);

            return ['set' => $set, ...$details];
        } catch (Throwable $e) {
            $this->log->fail($runId, $set, $e, $details);

            throw $e;
        } finally {
            if ($work !== null) {
                $this->removeTree($work);
            }
        }
    }

    /** @return array<string, mixed> */
    private function readManifest(BackupSettings $settings, string $dir): array
    {
        $doc = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

        if (! is_array($doc) || ! isset($doc['manifest'], $doc['hmac']) || ! is_array($doc['manifest'])) {
            throw BackupFailed::integrity('manifest is unreadable');
        }

        $expected = $settings->cipher->sign(json_encode($doc['manifest'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (! is_string($doc['hmac']) || ! hash_equals($expected, $doc['hmac'])) {
            throw BackupFailed::integrity('manifest signature mismatch');
        }

        return $doc['manifest'];
    }

    private function wipe(string $connection): void
    {
        $db = DB::connection($connection);
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (DatabaseTools::baseTables($connection) as $table) {
            $db->statement('DROP TABLE `'.str_replace('`', '', $table).'`');
        }

        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function importDump(BackupSettings $settings, string $encrypted): void
    {
        $db = DatabaseTools::live();
        $process = new Process([
            (string) config('backup.mysql_binary'), '--default-character-set=utf8mb4',
            '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['user'],
            (string) config('backup.restore_test_database'),
        ], null, ['MYSQL_PWD' => $db['password']], null, null);
        $process->setInput($settings->cipher->decryptFrom($encrypted));
        $process->run();

        if (! $process->isSuccessful()) {
            throw BackupFailed::toolFailed('mysql', (int) $process->getExitCode());
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function verifyDatabase(string $connection, array $manifest): array
    {
        $tables = $manifest['database']['tables'];
        $restored = DatabaseTools::baseTables($connection);

        if (array_diff($tables, $restored) !== [] || array_diff($restored, $tables) !== []) {
            throw BackupFailed::integrity('restored table set differs from the manifest');
        }

        $migrations = DB::connection($connection)->table('migrations')->orderBy('id')->pluck('migration')->all();

        if ($migrations !== $manifest['migrations']) {
            throw BackupFailed::integrity('restored migrations differ from the manifest');
        }

        $result = [];

        foreach ((array) config('backup.append_only_tables') as $table) {
            if (! in_array($table, $tables, true)) {
                continue;
            }

            $count = DB::connection($connection)->table($table)->count();
            $low = min($manifest['database']['rows_before'][$table], $manifest['database']['rows_after'][$table]);
            $high = max($manifest['database']['rows_before'][$table], $manifest['database']['rows_after'][$table]);

            if ($count < $low || $count > $high) {
                throw BackupFailed::integrity($table.' row count is outside the dump window');
            }

            $result[$table] = (string) $count;
        }

        return $result;
    }

    /** @param array<string, mixed> $manifest */
    private function verifyFiles(BackupSettings $settings, string $encrypted, string $work, array $manifest): int
    {
        $tar = $work.'/files.tar';
        $out = fopen($tar, 'xb');

        foreach ($settings->cipher->decryptFrom($encrypted) as $chunk) {
            fwrite($out, $chunk);
        }

        fclose($out);
        (new PharData($tar, 0, null, Phar::TAR))->extractTo($work.'/files', null, true);

        $count = 0;
        $bytes = 0;

        if (is_dir($work.'/files')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work.'/files', FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getFilename() !== '.empty') {
                    $count++;
                    $bytes += $file->getSize();
                }
            }
        }

        if ($count !== $manifest['files']['count'] || $bytes !== $manifest['files']['bytes']) {
            throw BackupFailed::integrity('restored files differ from the manifest');
        }

        return $count;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
