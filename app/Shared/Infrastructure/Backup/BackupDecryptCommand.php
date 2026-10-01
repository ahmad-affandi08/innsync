<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Illuminate\Console\Command;
use Throwable;

/**
 * Disaster-recovery helper: verifies a set against its signed manifest, then writes one decrypted artifact
 * for manual import. The output is plaintext PII; the operator must shred it after use (see the runbook).
 */
final class BackupDecryptCommand extends Command
{
    protected $signature = 'backup:decrypt {set : Backup set name} {artifact : database or files} {destination : New file to write (must not exist)}';

    protected $description = 'Verify a backup set and decrypt one artifact for manual disaster recovery';

    public function handle(): int
    {
        $artifact = match ($this->argument('artifact')) {
            'database' => 'database.sql.enc',
            'files' => 'files.tar.enc',
            default => null,
        };
        $destination = (string) $this->argument('destination');

        try {
            if ($artifact === null || file_exists($destination)) {
                throw BackupFailed::integrity('artifact must be database or files, and the destination must not exist');
            }

            $settings = BackupSettings::fromConfig();
            $set = (string) $this->argument('set');

            if (! in_array($set, BackupCatalog::sets($settings->path), true)) {
                throw BackupFailed::noBackupSet();
            }

            $dir = $settings->path.'/'.$set;
            $doc = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
            $signed = is_array($doc) && isset($doc['manifest'], $doc['hmac']) && is_string($doc['hmac'])
                && hash_equals($settings->cipher->sign(json_encode($doc['manifest'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $doc['hmac']);

            if (! $signed || ! hash_equals((string) ($doc['manifest']['artifacts'][$artifact] ?? ''), (string) hash_file('sha256', $dir.'/'.$artifact))) {
                throw BackupFailed::integrity('manifest or artifact checksum mismatch');
            }

            $out = fopen($destination, 'xb');
            chmod($destination, 0600);

            try {
                foreach ($settings->cipher->decryptFrom($dir.'/'.$artifact) as $chunk) {
                    fwrite($out, $chunk);
                }
            } catch (Throwable $e) {
                fclose($out);
                @unlink($destination);

                throw $e;
            }

            fclose($out);
        } catch (BackupFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->warn('Decrypted file contains plaintext personal data. Shred it after the restore.');

        return self::SUCCESS;
    }
}
