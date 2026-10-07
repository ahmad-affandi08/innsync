<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Console;

use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class InstallDefaultRolesCommand extends Command
{
    protected $signature = 'innsync:install-default-roles {--property= : Property id; every property when omitted}';

    protected $description = 'Create the starting roles (Receptionist, Housekeeping Supervisor, ...) where a property lacks them; roles that exist are not touched';

    public function handle(DatabaseDefaultRoleInstaller $installer): int
    {
        $only = $this->option('property');
        $ids = DB::table('properties')->when(is_string($only) && $only !== '', static fn ($q) => $q->where('id', $only))->pluck('id');

        foreach ($ids as $id) {
            $created = $installer->install((string) $id);
            $this->info(sprintf('%s: %d role(s) created%s', $id, count($created), $created === [] ? '' : ' ('.implode(', ', $created).')'));
        }

        return self::SUCCESS;
    }
}
