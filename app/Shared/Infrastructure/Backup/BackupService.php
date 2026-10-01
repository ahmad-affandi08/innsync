<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;

final class BackupService
{
    public function __construct(private readonly BackupRunLog $log) {}

    /** @return array{set: string, size_bytes: int} */
    public function run(): array
    {
        $settings = BackupSettings::fromConfig();
        $runId = $this->log->start('backup');
        $started = CarbonImmutable::now('UTC');
        $set = $started->format('Ymd\THis\Z').'-'.strtolower(Str::random(8));
        $dir = $settings->path.'/'.$set;
        $details = [];

        try {
            mkdir($dir, 0700);
            $tables = DatabaseTools::baseTables((string) config('database.default'));
            $before = DatabaseTools::rowCounts((string) config('database.default'), $tables);

            $dbBytes = $this->dumpDatabase($settings, $dir.'/database.sql.enc');
            $after = DatabaseTools::rowCounts((string) config('database.default'), $tables);
            $files = $this->archiveFiles($settings, $dir);

            $manifest = [
                'format' => 1,
                'set' => $set,
                'created_at' => $started->format('Y-m-d\TH:i:s\Z'),
                'app_version' => (string) config('app.version'),
                'database' => ['sql_bytes' => $dbBytes, 'tables' => $tables, 'rows_before' => $before, 'rows_after' => $after],
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'files' => $files,
                'artifacts' => [
                    'database.sql.enc' => hash_file('sha256', $dir.'/database.sql.enc'),
                    'files.tar.enc' => hash_file('sha256', $dir.'/files.tar.enc'),
                ],
            ];
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            file_put_contents($dir.'/manifest.json', json_encode(
                ['manifest' => $manifest, 'hmac' => $settings->cipher->sign($json)],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ));
            chmod($dir.'/manifest.json', 0600);

            $size = (int) filesize($dir.'/database.sql.enc') + (int) filesize($dir.'/files.tar.enc');
            $details = ['tables' => count($tables), 'sql_bytes' => $dbBytes, 'files' => $files['count']];
            $this->log->succeed($runId, $set, $size, $details);
            $this->prune($settings);

            return ['set' => $set, 'size_bytes' => $size];
        } catch (Throwable $e) {
            $this->removeDir($dir);
            $this->log->fail($runId, $set, $e, $details);

            throw $e;
        }
    }

    private function dumpDatabase(BackupSettings $settings, string $destination): int
    {
        $db = DatabaseTools::live();
        $process = new Process([
            (string) config('backup.mysqldump_binary'),
            '--single-transaction', '--quick', '--routines', '--triggers', '--events',
            '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4',
            '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['user'], $db['database'],
        ], null, ['MYSQL_PWD' => $db['password']], null, null);
        $writer = $settings->cipher->encryptTo($destination);

        try {
            $process->run(function (string $type, string $chunk) use ($writer): void {
                if ($type === Process::OUT) {
                    $writer->write($chunk);
                }
            });

            if (! $process->isSuccessful()) {
                throw BackupFailed::toolFailed('mysqldump', (int) $process->getExitCode());
            }

            return $writer->close();
        } catch (Throwable $e) {
            $writer->abort();

            throw $e;
        }
    }

    /** @return array{count: int, bytes: int} */
    private function archiveFiles(BackupSettings $settings, string $dir): array
    {
        $root = $this->filesRoot();
        $tar = $dir.'/files.tar';
        $count = 0;
        $bytes = 0;
        $archive = new PharData($tar, 0, null, Phar::TAR);

        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $archive->addFile($file->getPathname(), ltrim(substr($file->getPathname(), strlen($root)), '/'));
                    $count++;
                    $bytes += $file->getSize();
                }
            }
        }

        if ($count === 0) {
            $archive->addFromString('.empty', '');
        }

        unset($archive);
        $this->encryptFile($settings, $tar, $dir.'/files.tar.enc');

        return ['count' => $count, 'bytes' => $bytes];
    }

    private function encryptFile(BackupSettings $settings, string $plain, string $destination): void
    {
        $writer = $settings->cipher->encryptTo($destination);
        $in = fopen($plain, 'rb');

        try {
            while (! feof($in)) {
                $writer->write((string) fread($in, 1 << 20));
            }
            $writer->close();
        } finally {
            fclose($in);
            @unlink($plain);
        }
    }

    public function filesRoot(): string
    {
        return (string) config('filesystems.disks.private_files.root');
    }

    private function prune(BackupSettings $settings): void
    {
        $keep = config('backup.keep_last');

        if (! is_int($keep)) {
            return;
        }

        $sets = BackupCatalog::sets($settings->path);

        foreach (array_slice($sets, 0, max(0, count($sets) - $keep)) as $old) {
            $this->removeDir($settings->path.'/'.$old);
        }
    }

    private function removeDir(string $dir): void
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
