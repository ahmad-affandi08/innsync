<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local sandbox data only: one login per starting role of the demo property, so every role's menu and permissions can be tried by signing in as that role.
 * The email comes from the role name ("Night Auditor" becomes night-auditor@innsync.test). Runs after DevelopmentSeeder, never outside local/testing, safe to run again.
 */
final class DevelopmentRoleAccountsSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    private const PROPERTY_NAME = 'Demo Hotel';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $property = PropertyRecord::where('name', self::PROPERTY_NAME)->first();

        if ($property === null) {
            $this->command?->warn('Demo Hotel does not exist yet: run DevelopmentSeeder first.');

            return;
        }

        (new DatabaseDefaultRoleInstaller)->install($property->id);
        $made = [];

        foreach (DB::table('roles')->where('property_id', $property->id)->where('is_active', true)->where('name', '<>', 'Administrator')->orderBy('name')->get(['id', 'name']) as $role) {
            $email = Str::slug(str_replace('&', ' and ', $role->name)).'@innsync.test';
            $user = UserRecord::firstOrCreate(['email' => $email], ['name' => $role->name, 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()]);

            DB::table('user_role_assignments')->insertOrIgnore([[
                'id' => strtolower((string) Str::ulid()), 'property_id' => $property->id, 'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'property', 'scope_id' => $property->id,
                'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]]);
            $made[] = $email;
        }

        $this->command?->info(sprintf('%d role logins ready in %s, password %s: %s', count($made), self::PROPERTY_NAME, self::PASSWORD, implode(', ', $made)));
    }
}
