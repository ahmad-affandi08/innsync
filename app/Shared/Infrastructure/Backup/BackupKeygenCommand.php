<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use Illuminate\Console\Command;

final class BackupKeygenCommand extends Command
{
    protected $signature = 'backup:keygen';

    protected $description = 'Print a new backup encryption key (store it in .env and keep an offline copy)';

    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());
        $this->components->warn('Without this key, backups cannot be restored. Keep a copy outside the server.');

        return self::SUCCESS;
    }
}
