<?php

use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing property gets the starting roles (owner instruction 2026-10-07; see docs/OPERATIONS/ACCESS-ADMINISTRATION.md). A role whose name the
 * property already uses is left alone, so nothing a property set up is changed. New properties get them from `innsync:create-admin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $installer = new DatabaseDefaultRoleInstaller;

        foreach (DB::table('properties')->pluck('id') as $propertyId) {
            $installer->install((string) $propertyId);
        }
    }

    public function down(): void
    {
        // The roles may have been edited and given to people since; they are not removed. Deactivate them on the Roles screen instead.
    }
};
