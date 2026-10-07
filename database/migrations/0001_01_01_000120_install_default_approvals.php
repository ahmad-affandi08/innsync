<?php

use App\Modules\IdentityAccess\Infrastructure\Approval\DatabaseDefaultApprovalInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every property with an administrator gets a starting approver for each action that is refused without one (owner instruction 2026-10-07; see
 * docs/OPERATIONS/ACCESS-ADMINISTRATION.md). A property that already configured a policy keeps it. A property with no administrator yet is skipped: the
 * policies are made when `innsync:create-admin` creates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $installer = new DatabaseDefaultApprovalInstaller;

        foreach (DB::table('properties')->pluck('id') as $propertyId) {
            try {
                $installer->install((string) $propertyId);
            } catch (RuntimeException) {
                // No administrator yet; innsync:create-admin installs them.
            }
        }
    }

    public function down(): void
    {
        // Policies are versioned facts that approvals may refer to; they are not removed. Change them on the Approval policies screen.
    }
};
