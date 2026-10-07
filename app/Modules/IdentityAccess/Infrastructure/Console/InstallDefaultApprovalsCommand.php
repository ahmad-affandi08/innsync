<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Console;

use App\Modules\IdentityAccess\Infrastructure\Approval\DatabaseDefaultApprovalInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class InstallDefaultApprovalsCommand extends Command
{
    protected $signature = 'innsync:install-default-approvals {--property= : Property id; every property when omitted}';

    protected $description = 'Give each mandatory approval action (void, refund, leave, ...) a starting approver where a property has none; policies that exist are not touched';

    public function handle(DatabaseDefaultApprovalInstaller $installer): int
    {
        $only = $this->option('property');
        $status = self::SUCCESS;

        foreach (DB::table('properties')->when(is_string($only) && $only !== '', static fn ($q) => $q->where('id', $only))->pluck('id') as $id) {
            try {
                $made = $installer->install((string) $id);
                $this->info(sprintf('%s: %d policy(ies) created%s', $id, count($made), $made === [] ? '' : ' ('.implode(', ', $made).')'));
            } catch (Throwable $e) {
                $this->warn($e->getMessage());
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
